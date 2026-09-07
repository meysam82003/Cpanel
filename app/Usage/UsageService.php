<?php

declare(strict_types=1);

namespace App\Usage;

use App\Accounts\AccountRepository;
use App\Cpanel\CpanelApiException;
use App\Cpanel\UapiClient;

final class UsageService
{
    public function __construct(private readonly AccountRepository $accounts, private readonly UapiClient $cpanel)
    {
    }

    /** @return array<string,mixed> */
    public function overview(int $userId, int $accountId): array
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $connection = $this->accounts->connection($userId, $accountId);
        return [
            'account' => [
                'domain' => $account['main_domain'],
                'username' => $account['cpanel_username'],
                'home' => $account['root_path'],
                'status' => $account['status'],
                'last_check' => $account['last_checked_at'],
            ],
            'stats' => $this->callOptional($connection, 'StatsBar', 'get_stats', ['display' => 'diskusage|bandwidth|emailaccounts|mysqldatabases|subdomains|addon_domains|autoresponders|forwarders|ssl']),
            'resources' => $this->callOptional($connection, 'ResourceUsage', 'get_usages'),
            'bandwidth' => $this->callOptional($connection, 'Bandwidth', 'query', ['grouping' => 'domain|year|month']),
            'server' => $this->callOptional($connection, 'Variables', 'get_server_information'),
            'user' => $this->callOptional($connection, 'Variables', 'get_user_information'),
            'php' => $this->callOptional($connection, 'LangPHP', 'php_get_vhost_versions'),
        ];
    }

    /** @param array{base_url:string,username:string,token:string} $connection
     *  @param array<string,scalar> $parameters
     */
    private function callOptional(array $connection, string $module, string $function, array $parameters = []): mixed
    {
        try {
            return $this->cpanel->call($connection, $module, $function, $parameters)['data'];
        } catch (CpanelApiException $exception) {
            return ['available' => false, 'error_code' => $exception->safeCode];
        }
    }
}
