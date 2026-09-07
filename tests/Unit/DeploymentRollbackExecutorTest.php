<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppException;
use App\Deployment\DeploymentBackupVerifier;
use App\Deployment\DeploymentRollbackExecutor;
use App\Deployment\ZipPackageValidator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDeploymentFilesystem;
use ZipArchive;

final class DeploymentRollbackExecutorTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is required for verified deployment rollback tests.');
        }
        $this->tempRoot = sys_get_temp_dir() . '/tcm-rollback-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->tempRoot, 0700, true));
    }

    protected function tearDown(): void
    {
        if (!isset($this->tempRoot) || !is_dir($this->tempRoot)) {
            return;
        }
        foreach (glob($this->tempRoot . '/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->tempRoot);
    }

    public function testDirectoryRollbackUsesDurableAtomicCheckpoints(): void
    {
        $filesystem = $this->filesystemWithReleases();
        $states = [];
        $result = $this->executor($filesystem)->restore(1, 10, $this->deployment(), static function (string $state, array $metadata) use (&$states): void {
            $states[] = $state;
        });

        self::assertSame('atomic_directory_restore', $result['method']);
        self::assertFalse($result['reconciled']);
        self::assertSame('old', $filesystem->content('/home/alice/public_html/app/index.php'));
        self::assertFalse($filesystem->exists('/home/alice/.tcm-rollbacks/deployment-7-previous'));
        self::assertFalse($filesystem->exists('/home/alice/.tcm-rollbacks/deployment-7-current'));
        self::assertSame([
            'rollback_dir_quarantine_pending',
            'rollback_dir_quarantined',
            'rollback_dir_restore_pending',
            'rollback_dir_restored',
        ], $states);
    }

    public function testProviderTimeoutAfterRenameIsReconciledWithoutReplay(): void
    {
        $filesystem = $this->filesystemWithReleases();
        $filesystem->throwAfterMoveSource = '/home/alice/public_html/app';

        $result = $this->executor($filesystem)->restore(1, 10, $this->deployment());

        self::assertTrue($result['reconciled']);
        self::assertSame('old', $filesystem->content('/home/alice/public_html/app/index.php'));
        self::assertSame(1, count(array_filter($filesystem->calls, static fn (array $call): bool => $call['operation'] === 'move' && $call['path'] === '/home/alice/public_html/app')));
    }

    public function testInterruptedRestorePendingCompletesFromFilesystemEvidence(): void
    {
        $filesystem = new InMemoryDeploymentFilesystem();
        $filesystem->seedDirectory('/home/alice/public_html/app');
        $filesystem->seedFile('/home/alice/public_html/app/index.php', 'old');
        $filesystem->seedDirectory('/home/alice/.tcm-rollbacks/deployment-7-current');
        $filesystem->seedFile('/home/alice/.tcm-rollbacks/deployment-7-current/index.php', 'new');
        $deployment = $this->deployment();
        $deployment['switch_state'] = 'rollback_dir_restore_pending';

        $result = $this->executor($filesystem)->restore(1, 10, $deployment);

        self::assertTrue($result['reconciled']);
        self::assertSame('old', $filesystem->content('/home/alice/public_html/app/index.php'));
        self::assertFalse($filesystem->exists('/home/alice/.tcm-rollbacks/deployment-7-current'));
    }

    public function testFailedRestorePutsCurrentReleaseBackAndPersistsRetryPoint(): void
    {
        $filesystem = $this->filesystemWithReleases();
        $filesystem->throwBeforeMoveSource = '/home/alice/.tcm-rollbacks/deployment-7-previous';
        $states = [];

        try {
            $this->executor($filesystem)->restore(1, 10, $this->deployment(), static function (string $state, array $metadata) use (&$states): void {
                $states[] = $state;
            });
            self::fail('A deterministic rollback activation failure was ignored.');
        } catch (AppException $exception) {
            self::assertSame('rollback_restore_failed', $exception->safeCode);
        }

        self::assertSame('new', $filesystem->content('/home/alice/public_html/app/index.php'));
        self::assertSame('old', $filesystem->content('/home/alice/.tcm-rollbacks/deployment-7-previous/index.php'));
        self::assertSame('rollback_dir_ready', end($states));
    }

    public function testNewDestinationRollbackRemovesOnlyManagedRelease(): void
    {
        $filesystem = new InMemoryDeploymentFilesystem();
        $filesystem->seedDirectory('/home/alice/public_html/new-site');
        $filesystem->seedFile('/home/alice/public_html/new-site/index.php', 'new');
        $deployment = $this->deployment();
        $deployment['destination'] = '/home/alice/public_html/new-site';
        $deployment['destination_existed'] = 0;
        $deployment['rollback_path'] = null;

        $result = $this->executor($filesystem)->restore(1, 10, $deployment);

        self::assertSame('remove_new_destination', $result['method']);
        self::assertFalse($filesystem->exists('/home/alice/public_html/new-site'));
        self::assertFalse($filesystem->exists('/home/alice/.tcm-rollbacks/deployment-7-current'));
    }

    public function testArchiveFallbackIsIntegrityCheckedStagedAndActivated(): void
    {
        $filesystem = new InMemoryDeploymentFilesystem();
        $filesystem->seedDirectory('/home/alice/old/app');
        $filesystem->seedFile('/home/alice/old/app/index.php', 'old-from-archive');
        $filesystem->compressDirectory(1, 10, '/home/alice/old/app', '/home/alice/.tcm-backups/deployment-7.zip');
        $filesystem->deleteTree(1, 10, '/home/alice/old');
        $filesystem->seedDirectory('/home/alice/public_html/app');
        $filesystem->seedFile('/home/alice/public_html/app/index.php', 'new');
        $archive = $filesystem->content('/home/alice/.tcm-backups/deployment-7.zip');
        self::assertNotNull($archive);
        $deployment = $this->deployment();
        $deployment['rollback_path'] = null;
        $deployment['backup_ref'] = '/home/alice/.tcm-backups/deployment-7.zip';
        $deployment['backup_checksum'] = hash('sha256', $archive);

        $result = $this->executor($filesystem)->restore(1, 10, $deployment);

        self::assertSame('staged_archive_restore', $result['method']);
        self::assertSame('old-from-archive', $filesystem->content('/home/alice/public_html/app/index.php'));
        self::assertFalse($filesystem->exists('/home/alice/.tcm-rollbacks/deployment-7-archive-restore'));
    }

    public function testCorruptArchiveIsRejectedBeforeLiveDestinationChanges(): void
    {
        $filesystem = new InMemoryDeploymentFilesystem();
        $filesystem->seedDirectory('/home/alice/public_html/app');
        $filesystem->seedFile('/home/alice/public_html/app/index.php', 'new');
        $filesystem->seedFile('/home/alice/.tcm-backups/deployment-7.zip', 'not-a-zip');
        $deployment = $this->deployment();
        $deployment['rollback_path'] = null;
        $deployment['backup_ref'] = '/home/alice/.tcm-backups/deployment-7.zip';
        $deployment['backup_checksum'] = hash('sha256', 'not-a-zip');

        $this->expectException(AppException::class);
        try {
            $this->executor($filesystem)->restore(1, 10, $deployment);
        } finally {
            self::assertSame('new', $filesystem->content('/home/alice/public_html/app/index.php'));
            self::assertFalse($filesystem->exists('/home/alice/.tcm-rollbacks/deployment-7-current'));
        }
    }

    private function executor(InMemoryDeploymentFilesystem $filesystem): DeploymentRollbackExecutor
    {
        $verifier = new DeploymentBackupVerifier($filesystem, new ZipPackageValidator(), $this->tempRoot);
        return new DeploymentRollbackExecutor($filesystem, $verifier);
    }

    private function filesystemWithReleases(): InMemoryDeploymentFilesystem
    {
        $filesystem = new InMemoryDeploymentFilesystem();
        $filesystem->seedDirectory('/home/alice/public_html/app');
        $filesystem->seedFile('/home/alice/public_html/app/index.php', 'new');
        $filesystem->seedDirectory('/home/alice/.tcm-rollbacks/deployment-7-previous');
        $filesystem->seedFile('/home/alice/.tcm-rollbacks/deployment-7-previous/index.php', 'old');
        return $filesystem;
    }

    /** @return array<string,mixed> */
    private function deployment(): array
    {
        return [
            'id' => 7,
            'destination' => '/home/alice/public_html/app',
            'stage_path' => '/home/alice/.tcm-deploy/deployment-7/release-abc',
            'rollback_path' => '/home/alice/.tcm-rollbacks/deployment-7-previous',
            'backup_ref' => null,
            'backup_checksum' => null,
            'destination_existed' => 1,
            'switch_state' => 'rollback_pending',
        ];
    }
}
