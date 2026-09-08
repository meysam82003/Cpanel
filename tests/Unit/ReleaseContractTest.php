<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use PHPUnit\Framework\TestCase;

final class ReleaseContractTest extends TestCase
{
    public function testPackageVersionIsARepositorySourceOfTruth(): void
    {
        $root = dirname(__DIR__, 2);
        $version = trim((string) file_get_contents($root . '/VERSION'));

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version);
        self::assertSame($version, Config::packageVersion());
        self::assertSame($version, Config::app('version'));

        $installer = (string) file_get_contents($root . '/app/Installer/InstallerService.php');
        self::assertStringContainsString("'APP_VERSION' => Config::packageVersion()", $installer);
        self::assertStringContainsString("'app_version' => Config::packageVersion()", $installer);
    }

    public function testUpdaterIsCliOnlyAuthenticatedLockedAndAudited(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/cli/update.php');

        self::assertStringContainsString("PHP_SAPI !== 'cli'", $source);
        self::assertStringContainsString("hash_equals(Env::require('CRON_SECRET')", $source);
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $source);
        self::assertStringContainsString("->migrate(", $source);
        self::assertStringContainsString("->seed(", $source);
        self::assertStringContainsString('HelpSeeder', $source);
        self::assertStringContainsString("'system.update'", $source);
        self::assertStringNotContainsString('$exception->getMessage()', $source);
    }

    public function testReleaseBuilderRejectsSecretsAndVerifiesArchiveIntegrity(): void
    {
        $root = dirname(__DIR__, 2);
        $builder = (string) file_get_contents($root . '/scripts/build-release.sh');
        $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');

        self::assertStringContainsString('git archive --format=tar HEAD', $builder);
        self::assertStringContainsString('CHECKSUMS.sha256', $builder);
        self::assertStringContainsString('unzip -tqq', $builder);
        self::assertStringContainsString('sha256sum -c CHECKSUMS.sha256', $builder);
        self::assertStringContainsString('sha256sum -c', $builder);
        self::assertStringContainsString('installed.lock', $builder);
        self::assertStringContainsString('PRIVATE KEY', $builder);
        self::assertStringContainsString('actions/upload-artifact@v4', $workflow);
        self::assertStringContainsString('needs: [test, installation]', $workflow);
    }
}
