<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\AppException;
use App\FileManager\ArchiveSafetyValidator;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ArchiveSafetyValidatorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAcceptsSafeZipAndReturnsIntegritySummary(): void
    {
        $path = $this->zip([
            'release/index.php' => '<?php echo "ready";',
            'release/assets/app.css' => 'body{color:#123}',
        ]);

        $summary = (new ArchiveSafetyValidator())->validate($path, 'release.zip');

        self::assertSame('zip', $summary['format']);
        self::assertSame(2, $summary['files']);
        self::assertSame(['release'], $summary['top_level']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['sha256']);
        self::assertGreaterThan(0, $summary['uncompressed_bytes']);
    }

    public function testBlocksZipSlipAndNormalizedDuplicatePaths(): void
    {
        $traversal = $this->zip(['../escape.php' => 'blocked']);
        $this->assertSafeCode('zip_slip_blocked', static fn (): array => (new ArchiveSafetyValidator())->validate($traversal, 'payload.zip'));

        $duplicate = $this->zip(['dir/file.txt' => 'one', 'dir\\file.txt' => 'two']);
        $this->assertSafeCode('archive_duplicate_path', static fn (): array => (new ArchiveSafetyValidator())->validate($duplicate, 'payload.zip'));
    }

    public function testBlocksZipSymlinksAndCompressionBombs(): void
    {
        $symlink = $this->zip(['link' => 'target'], ['link']);
        $this->assertSafeCode('archive_special_entry_blocked', static fn (): array => (new ArchiveSafetyValidator())->validate($symlink, 'payload.zip'));

        $bomb = $this->zip(['large.txt' => str_repeat('A', 2_000_000)]);
        $this->assertSafeCode('zip_bomb_blocked', static fn (): array => (new ArchiveSafetyValidator())->validate($bomb, 'payload.zip', 100, 5_000_000, 20));
    }

    public function testValidatesTarHeadersAndBlocksTarTraversal(): void
    {
        $safe = $this->tar(['release/index.php' => 'safe']);
        $summary = (new ArchiveSafetyValidator())->validate($safe, 'release.tar');
        self::assertSame('tar', $summary['format']);
        self::assertSame(['release'], $summary['top_level']);

        $traversal = $this->tar(['../escape.php' => 'blocked']);
        $this->assertSafeCode('zip_slip_blocked', static fn (): array => (new ArchiveSafetyValidator())->validate($traversal, 'payload.tar'));
    }

    public function testSingleGzipUsesOriginalSafeOutputNameAndCountsExpansion(): void
    {
        $path = $this->temporaryPath('.gz');
        file_put_contents($path, gzencode('hello archive', 6, ZLIB_ENCODING_GZIP));

        $summary = (new ArchiveSafetyValidator())->validate($path, 'database.sql.gz');

        self::assertSame('gz', $summary['format']);
        self::assertSame(['database.sql'], $summary['top_level']);
        self::assertSame(strlen('hello archive'), $summary['uncompressed_bytes']);
    }

    public function testBlocksTraversalInEmbeddedGzipFilename(): void
    {
        $path = $this->temporaryPath('.gz');
        $header = "\x1f\x8b\x08\x08" . pack('V', 0) . "\x00\xff" . "../escape.php\0";
        file_put_contents($path, $header . gzdeflate('blocked') . pack('V', crc32('blocked')) . pack('V', 7));

        $this->assertSafeCode('zip_slip_blocked', static fn (): array => (new ArchiveSafetyValidator())->validate($path, 'payload.gz'));
    }

    /** @param array<string,string> $entries @param list<string> $symlinks */
    private function zip(array $entries, array $symlinks = []): string
    {
        $path = $this->temporaryPath('.zip');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        foreach ($symlinks as $name) {
            self::assertTrue($zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16));
        }
        self::assertTrue($zip->close());
        return $path;
    }

    /** @param array<string,string> $entries */
    private function tar(array $entries): string
    {
        $path = $this->temporaryPath('.tar');
        $archive = '';
        foreach ($entries as $name => $contents) {
            $size = strlen($contents);
            $header = str_pad($name, 100, "\0")
                . sprintf("%07o\0", 0644)
                . sprintf("%07o\0", 0)
                . sprintf("%07o\0", 0)
                . sprintf("%011o\0", $size)
                . sprintf("%011o\0", 1_700_000_000)
                . str_repeat(' ', 8)
                . '0'
                . str_repeat("\0", 100)
                . "ustar\0"
                . '00'
                . str_pad('tester', 32, "\0")
                . str_pad('tester', 32, "\0")
                . str_repeat("\0", 8 + 8 + 155 + 12);
            self::assertSame(512, strlen($header));
            $checksum = array_sum(unpack('C*', $header));
            $header = substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);
            $padding = (512 - ($size % 512)) % 512;
            $archive .= $header . $contents . str_repeat("\0", $padding);
        }
        file_put_contents($path, $archive . str_repeat("\0", 1024));
        return $path;
    }

    private function temporaryPath(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/archive-safety-' . bin2hex(random_bytes(12)) . $suffix;
        $this->temporaryFiles[] = $path;
        return $path;
    }

    /** @param callable():array<string,mixed> $operation */
    private function assertSafeCode(string $expected, callable $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe archive was accepted.');
        } catch (AppException $exception) {
            self::assertSame($expected, $exception->safeCode);
        }
    }
}
