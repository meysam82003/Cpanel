<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Core\AppException;
use App\Security\HostValidator;

final class HealthCheckService
{
    public function __construct(private readonly HostValidator $hosts)
    {
    }

    /** @return array{url:string,status:int,latency_ms:int,healthy:bool} */
    public function check(string $url, string $mainDomain): array
    {
        $parts = parse_url($url);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $mainDomain = strtolower(rtrim($mainDomain, '.'));
        if ($host === '' || $mainDomain === '' || ($host !== $mainDomain && !str_ends_with($host, '.' . $mainDomain))) {
            throw new AppException('Health-check URL must use the account main domain or one of its subdomains.', 422, 'invalid_health_check_host', [], 'deploy.health');
        }
        $origin = 'https://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        $validated = $this->hosts->validate($origin);
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $target = $validated['base_url'] . ($path === '' ? '/' : $path) . $query;
        $lastStatus = 0;
        $lastErrno = 0;
        foreach ($validated['ips'] as $ip) {
            $bodyBytes = 0;
            $curl = curl_init($target);
            curl_setopt_array($curl, [
                CURLOPT_HTTPGET => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$validated['host'] . ':' . $validated['port'] . ':' . $ip],
                CURLOPT_USERAGENT => 'TelegramCpanelManager-HealthCheck/1.0',
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bodyBytes): int {
                    $bodyBytes += strlen($chunk);
                    return $bodyBytes > 65_536 ? 0 : strlen($chunk);
                },
            ]);
            $started = microtime(true);
            curl_exec($curl);
            $lastErrno = curl_errno($curl);
            $lastStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $latency = (int) round((microtime(true) - $started) * 1000);
            curl_close($curl);
            if ($lastErrno === 0 && $lastStatus >= 200 && $lastStatus < 400) {
                return ['url' => $url, 'status' => $lastStatus, 'latency_ms' => $latency, 'healthy' => true];
            }
        }
        return ['url' => $url, 'status' => $lastStatus, 'latency_ms' => 0, 'healthy' => false];
    }
}
