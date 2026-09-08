<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ErrorGuidanceService;
use PHPUnit\Framework\TestCase;

final class ErrorGuidanceServiceTest extends TestCase
{
    public function testBackupErrorsReceiveActionableBilingualGuidance(): void
    {
        $service = new ErrorGuidanceService();
        $persian = $service->for('backup_integrity_failed', 'fa');
        $english = $service->for('backup_restore_provider_unsupported', 'en');

        self::assertNotEmpty($persian['causes']);
        self::assertNotEmpty($persian['actions']);
        self::assertStringContainsString('Backup', $persian['title']);
        self::assertNotEmpty($english['causes']);
        self::assertNotEmpty($english['actions']);
        self::assertStringContainsString('backup', strtolower($english['title']));
    }

    public function testDeploymentBackupFailureKeepsDeploymentGuidance(): void
    {
        $guidance = (new ErrorGuidanceService())->for('deployment_backup_integrity_failed', 'en');

        self::assertStringContainsString('deployment', strtolower($guidance['title']));
    }
}
