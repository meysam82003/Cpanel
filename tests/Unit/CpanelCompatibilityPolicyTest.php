<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Logger;
use App\Cpanel\CpanelApiException;
use App\Cpanel\UapiClient;
use App\Security\HostValidator;
use PHPUnit\Framework\TestCase;

final class CpanelCompatibilityPolicyTest extends TestCase
{
    private UapiClient $client;

    protected function setUp(): void
    {
        $this->client = new UapiClient(new HostValidator(), new Logger(sys_get_temp_dir() . '/tcm-test-log'));
    }

    public function testMissingUapiFunctionAllowsCompatibilityFallback(): void
    {
        $exception = new CpanelApiException(
            'Safe provider error.',
            'cpanel_operation_failed',
            ['errors' => ['The API function “addaddondomain” in module “AddonDomain” could not be found.']]
        );

        self::assertTrue($this->client->isOperationUnavailable($exception));
    }

    public function testPermissionAndValidationErrorsNeverAllowFallback(): void
    {
        self::assertFalse($this->client->isOperationUnavailable(new CpanelApiException(
            'Safe provider error.',
            'cpanel_operation_failed',
            ['errors' => ['You do not have permission to create this domain.']]
        )));
        self::assertFalse($this->client->isOperationUnavailable(new CpanelApiException(
            'Safe provider error.',
            'cpanel_auth_failed',
            ['http_status' => 403],
            401
        )));
    }

    public function testMissingHttpRouteAllowsCompatibilityFallback(): void
    {
        self::assertTrue($this->client->isOperationUnavailable(new CpanelApiException(
            'Safe provider error.',
            'cpanel_http_error',
            ['http_status' => 404]
        )));
    }
}
