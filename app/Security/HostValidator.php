<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\AppException;

final class HostValidator
{
    /** @param list<int> $allowedPorts */
    public function __construct(
        private readonly bool $allowPrivateHosts = false,
        private readonly array $allowedPorts = [2083, 443],
        private readonly int $defaultPort = 2083,
    ) {
    }

    /** @return array{base_url:string,host:string,port:int,ips:list<string>} */
    public function validate(string $input): array
    {
        $input = trim($input);
        if (!preg_match('#^https://#i', $input)) {
            $input = 'https://' . $input;
        }
        $parts = parse_url($input);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new AppException('Enter a valid HTTPS cPanel URL.', 422, 'invalid_cpanel_url', [], 'hosts.add');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new AppException('Credentials, query strings, and fragments are not allowed in the host URL.', 422, 'invalid_cpanel_url');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (isset($parts['path']) && !in_array((string) $parts['path'], ['', '/'], true)) {
            throw new AppException('A cPanel host URL cannot contain an application path.', 422, 'invalid_cpanel_url', [], 'hosts.add');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'metadata.google.internal') {
            $this->blocked($host);
        }
        if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new AppException('The cPanel hostname is invalid.', 422, 'invalid_cpanel_host');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : $this->defaultPort;
        if (!in_array($port, $this->allowedPorts, true)) {
            throw new AppException('This cPanel port is not allowed by policy.', 422, 'cpanel_port_not_allowed', ['port' => $port], 'security.ssrf');
        }

        $ips = $this->resolve($host);
        if ($ips === []) {
            throw new AppException('The cPanel hostname could not be resolved.', 422, 'cpanel_dns_failed', [], 'hosts.connection-errors');
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip) && !($this->allowPrivateHosts && $this->isAllowedPrivateIp($ip))) {
                $this->blocked($ip);
            }
        }

        return [
            'base_url' => 'https://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port,
            'host' => $host,
            'port' => $port,
            'ips' => array_values(array_unique($ips)),
        ];
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ip'])) {
                $ips[] = (string) $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }
        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && !str_starts_with(strtolower($ip), 'fc')
            && !str_starts_with(strtolower($ip), 'fd');
    }

    private function isAllowedPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $packed = inet_pton($ip);
            if (!is_string($packed)) {
                return false;
            }
            $octets = array_values(unpack('C4', $packed));
            return $octets[0] === 10
                || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
                || ($octets[0] === 192 && $octets[1] === 168);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            // Only fc00::/7 (IPv6 unique-local) is opt-in. Loopback,
            // link-local, multicast, unspecified and mapped ranges remain blocked.
            return is_string($packed) && (ord($packed[0]) & 0xfe) === 0xfc;
        }
        return false;
    }

    private function blocked(string $target): never
    {
        throw new AppException(
            'The host resolves to a private, reserved, loopback, or metadata address and was blocked.',
            403,
            'ssrf_target_blocked',
            ['target' => $target],
            'security.ssrf'
        );
    }
}
