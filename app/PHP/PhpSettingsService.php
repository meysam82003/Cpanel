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
            'ini' => $this->optional($connection, 'LangPHP', 'php_ini_get_user_basic_directives', ['type' => 'home']),
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
    public function setIni(int $userId, int $accountId, string $type, array $directives, ?string $vhost = null): array
    {
        if (!in_array($type, ['home', 'vhost'], true) || $directives === [] || count($directives) > 50) {
            throw new AppException('PHP INI update is invalid.', 422, 'invalid_php_ini_update', [], 'php.overview');
        }
        $vhost = $vhost === null ? null : strtolower(trim($vhost));
        if ($type === 'vhost' && ($vhost === null || filter_var($vhost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            throw new AppException('Select a valid virtual host for the PHP INI update.', 422, 'invalid_php_vhost', [], 'php.overview');
        }
        $allowed = ['allow_url_fopen', 'display_errors', 'enable_dl', 'file_uploads', 'max_execution_time', 'max_input_time', 'max_input_vars', 'memory_limit', 'post_max_size', 'session.gc_maxlifetime', 'session.save_path', 'upload_max_filesize', 'zlib.output_compression'];
        $payload = [];
        $position = 1;
        foreach ($directives as $key => $value) {
            if (!in_array($key, $allowed, true) || strlen((string) $value) > 100 || preg_match('/[\r\n\x00]/', (string) $value)) {
                throw new AppException('A PHP INI directive is invalid or not allowed.', 422, 'invalid_php_ini_directive', ['directive' => $key], 'php.overview');
            }
            $payload['directive-' . $position] = $key . ':' . (string) $value;
            $position++;
        }
        $payload['type'] = $type;
        if ($type === 'vhost') {
            $payload['vhost'] = $vhost;
        }
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'LangPHP', 'php_ini_set_user_basic_directives', $payload, 'POST', [], false);
        $this->audit->record($userId, $accountId, 'php.ini_change', 'success', 'php_ini', $type === 'vhost' ? (string) $vhost : 'home', ['directives' => array_keys($directives)]);
        return ['cpanel' => $result['data']];
    }

    /** @param array{base_url:string,username:string,token:string} $connection */
    private function optional(array $connection, string $module, string $function, array $parameters = []): mixed
    {
        try {
            return $this->cpanel->call($connection, $module, $function, $parameters)['data'];
        } catch (CpanelApiException $exception) {
            return ['available' => false, 'error_code' => $exception->safeCode];
        }
    }
}
