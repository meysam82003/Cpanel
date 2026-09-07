<?php

declare(strict_types=1);

namespace App\PHP;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Cpanel\CpanelApiException;
use App\Cpanel\UapiClient;

final class PhpSettingsService
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function information(int $userId, int $accountId): array
    {
        $connection = $this->accounts->connection($userId, $accountId);
        return [
            'vhosts' => $this->cpanel->call($connection, 'LangPHP', 'php_get_vhost_versions')['data'],
            'installed_versions' => $this->optional($connection, 'LangPHP', 'php_get_installed_versions'),
            'system_default' => $this->optional($connection, 'LangPHP', 'php_get_system_default_version'),
            'ini' => $this->optional($connection, 'LangPHP', 'php_ini_get_user_basic_directives'),
        ];
    }

    /** @param list<string> $vhosts
     * @return array<string,mixed>
     */
    public function setVersion(int $userId, int $accountId, array $vhosts, string $version): array
    {
        if ($vhosts === [] || count($vhosts) > 100 || !preg_match('/^(?:ea-)?php[0-9]{2,3}$|^inherit$/', $version)) {
            throw new AppException('PHP version selection is invalid.', 422, 'invalid_php_version', [], 'php.overview');
        }
        foreach ($vhosts as $vhost) {
            if (filter_var($vhost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new AppException('A selected PHP virtual host is invalid.', 422, 'invalid_php_vhost', [], 'php.overview');
            }
        }
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'LangPHP', 'php_set_vhost_versions', ['version' => $version, 'vhost' => $vhosts], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'php.version_change', 'success', 'php', implode(',', $vhosts), ['version' => $version]);
        return ['cpanel' => $result['data']];
    }

    /** @param array<string,string|int|float> $directives
     *  @return array<string,mixed>
     */
    public function setIni(int $userId, int $accountId, string $type, array $directives): array
    {
        if (!in_array($type, ['home', 'domain'], true) || $directives === [] || count($directives) > 50) {
            throw new AppException('PHP INI update is invalid.', 422, 'invalid_php_ini_update', [], 'php.overview');
        }
        $allowed = ['allow_url_fopen', 'display_errors', 'enable_dl', 'file_uploads', 'max_execution_time', 'max_input_time', 'max_input_vars', 'memory_limit', 'post_max_size', 'session.gc_maxlifetime', 'session.save_path', 'upload_max_filesize', 'zlib.output_compression'];
        $payload = [];
        foreach ($directives as $key => $value) {
            if (!in_array($key, $allowed, true) || strlen((string) $value) > 100 || preg_match('/[\r\n\x00]/', (string) $value)) {
                throw new AppException('A PHP INI directive is invalid or not allowed.', 422, 'invalid_php_ini_directive', ['directive' => $key], 'php.overview');
            }
            $payload['directive-' . $key] = $value;
        }
        $payload['type'] = $type;
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'LangPHP', 'php_ini_set_user_basic_directives', $payload, 'POST', [], false);
        $this->audit->record($userId, $accountId, 'php.ini_change', 'success', 'php_ini', $type, ['directives' => array_keys($directives)]);
        return ['cpanel' => $result['data']];
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
