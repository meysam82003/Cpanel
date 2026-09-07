<?php

declare(strict_types=1);

namespace App\SSL;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Cpanel\CpanelApiException;
use App\Cpanel\UapiClient;

final class SslService
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function status(int $userId, int $accountId): array
    {
        $connection = $this->accounts->connection($userId, $accountId);
        $items = $this->cpanel->call($connection, 'SSL', 'list_ssl_items');
        $installed = $this->optional($connection, 'SSL', 'installed_hosts');
        $autoSsl = $this->optional($connection, 'SSL', 'get_autossl_problems');
        return ['items' => $items['data'], 'installed_hosts' => $installed, 'autossl_problems' => $autoSsl];
    }

    /** @return array<string,mixed> */
    public function autoSslEligibility(int $userId, int $accountId): array
    {
        $connection = $this->accounts->connection($userId, $accountId);
        try {
            $this->cpanel->call($connection, 'Features', 'has_feature', ['name' => 'autossl']);
            $enabled = true;
        } catch (CpanelApiException $exception) {
            if ($exception->safeCode !== 'cpanel_operation_failed') {
                throw $exception;
            }
            $enabled = false;
        }
        return [
            'autossl' => [
                'available' => $enabled,
                'check_in_progress' => $this->optional($connection, 'SSL', 'is_autossl_check_in_progress'),
                'problems' => $enabled ? $this->optional($connection, 'SSL', 'get_autossl_problems') : [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function runAutoSsl(int $userId, int $accountId): array
    {
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'SSL', 'start_autossl_check', [], 'GET', [], false);
        $this->audit->record($userId, $accountId, 'ssl.autossl_run', 'success', 'ssl', 'autossl');
        return ['cpanel' => $result['data']];
    }

    /** @param list<string> $domains
     *  @return array<string,mixed>
     */
    public function certificateInfo(int $userId, int $accountId, array $domains): array
    {
        if ($domains === [] || count($domains) > 100) {
            throw new AppException('Select between 1 and 100 domains.', 422, 'invalid_ssl_domains', [], 'ssl.autossl');
        }
        foreach ($domains as $domain) {
            if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new AppException('One selected SSL domain is invalid.', 422, 'invalid_ssl_domain', [], 'ssl.autossl');
            }
        }
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'SSL', 'fetch_certificates_for_fqdns', ['domains' => $domains]);
        return ['certificates' => $result['data']];
    }

    /** @param array{base_url:string,username:string,token:string} $connection */
    private function optional(array $connection, string $module, string $function): mixed
    {
        try {
            return $this->cpanel->call($connection, $module, $function)['data'];
        } catch (CpanelApiException $exception) {
            return ['available' => false, 'error_code' => $exception->safeCode];
        }
    }
}
