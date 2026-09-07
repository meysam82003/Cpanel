<?php

declare(strict_types=1);

namespace App\Cpanel;

use App\Core\Database;

final class CapabilityDetector
{
    /** @var array<string, array{module:string,function:string,params:array<string,scalar>,api?:string}> */
    private const PROBES = [
        'files' => ['module' => 'Fileman', 'function' => 'list_files', 'params' => ['dir' => '.', 'limit' => 1]],
        'mysql' => ['module' => 'Mysql', 'function' => 'list_databases', 'params' => []],
        'domains' => ['module' => 'DomainInfo', 'function' => 'domains_data', 'params' => ['format' => 'hash']],
        'email' => ['module' => 'Email', 'function' => 'list_pops_with_disk', 'params' => ['maxaccounts' => 1]],
        'ssl' => ['module' => 'SSL', 'function' => 'list_ssl_items', 'params' => []],
        'cron' => ['module' => 'Cron', 'function' => 'listcron', 'params' => [], 'api' => 'api2'],
        'backup' => ['module' => 'Backup', 'function' => 'list_backups', 'params' => []],
        'usage' => ['module' => 'StatsBar', 'function' => 'get_stats', 'params' => []],
        'php' => ['module' => 'LangPHP', 'function' => 'php_get_vhost_versions', 'params' => []],
    ];

    public function __construct(private readonly Database $database, private readonly UapiClient $client)
    {
    }

    /** @param array{base_url:string,username:string,token:string} $connection
     *  @return array<string, array{available:bool,writable:bool,details:array<string,mixed>}>
     */
    public function detect(int $accountId, array $connection): array
    {
        $features = [];
        try {
            $featureResult = $this->client->call($connection, 'Features', 'list_features');
            $featureData = is_array($featureResult['data']) ? $featureResult['data'] : [];
            if (!array_is_list($featureData)) {
                foreach ($featureData as $name => $enabled) {
                    if (is_string($name) && is_scalar($enabled)) {
                        $features[strtolower($name)] = (bool) $enabled;
                    }
                }
            } else {
                foreach ($featureData as $item) {
                    if (is_array($item) && isset($item['name'])) {
                        $features[strtolower((string) $item['name'])] = (bool) ($item['is_enabled'] ?? $item['enabled'] ?? false);
                    }
                }
            }
        } catch (CpanelApiException) {
            // Safe endpoint probes below remain authoritative when the feature list is unavailable.
        }

        $detected = [];
        foreach (self::PROBES as $capability => $probe) {
            try {
                $result = ($probe['api'] ?? 'uapi') === 'api2'
                    ? $this->client->callLegacyApi2($connection, $probe['module'], $probe['function'], $probe['params'])
                    : $this->client->call($connection, $probe['module'], $probe['function'], $probe['params']);
                $available = true;
                $details = ['source' => 'probe', 'provider_api' => $probe['api'] ?? 'uapi', 'warnings' => $result['warnings']];
            } catch (CpanelApiException $exception) {
                $available = false;
                $details = ['source' => 'probe', 'error_code' => $exception->safeCode];
            }
            $writable = $available && $this->inferWritable($capability, $features);
            $detected[$capability] = compact('available', 'writable', 'details');
            $this->database->execute(
                'INSERT INTO account_capabilities (account_id, capability, available, writable, details_json, detected_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP) '
                . 'ON DUPLICATE KEY UPDATE available = VALUES(available), writable = VALUES(writable), details_json = VALUES(details_json), detected_at = CURRENT_TIMESTAMP',
                [$accountId, $capability, (int) $available, (int) $writable, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]
            );
        }
        $this->database->execute('UPDATE cpanel_accounts SET capability_checked_at = CURRENT_TIMESTAMP WHERE id = ?', [$accountId]);
        return $detected;
    }

    /** @param array<string, bool> $features */
    private function inferWritable(string $capability, array $features): bool
    {
        $aliases = match ($capability) {
            'files' => ['filemanager', 'file_manager'],
            'mysql' => ['mysql', 'databases'],
            'domains' => ['addondomains', 'subdomains', 'domains'],
            'email' => ['popaccts', 'email'],
            'ssl' => ['sslmanager', 'tls_status'],
            'cron' => ['cron', 'cronjobs'],
            'backup' => ['backup', 'backupwizard'],
            'php' => ['multiphp', 'php'],
            default => [],
        };
        foreach ($aliases as $alias) {
            if (($features[$alias] ?? false) === true) {
                return true;
            }
        }
        return $features === [];
    }
}
