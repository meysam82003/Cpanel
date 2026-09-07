<?php

declare(strict_types=1);

namespace App\Domains;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Cpanel\CpanelApiException;
use App\Cpanel\UapiClient;

final class DomainService
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function list(int $userId, int $accountId): array
    {
        $connection = $this->accounts->connection($userId, $accountId);
        $summary = $this->cpanel->call($connection, 'DomainInfo', 'list_domains');
        $details = $this->cpanel->call($connection, 'DomainInfo', 'domains_data', ['format' => 'hash']);
        return ['summary' => $summary['data'], 'domains' => $details['data']];
    }

    /** @return array<string,mixed> */
    public function add(int $userId, int $accountId, string $domain, string $documentRoot): array
    {
        $domain = $this->domain($domain);
        $documentRoot = $this->documentRoot($documentRoot);
        $connection = $this->accounts->connection($userId, $accountId);
        $subdomain = $this->addonSubdomainLabel($domain);
        $parameters = [
            'newdomain' => $domain,
            'subdomain' => $subdomain,
            'dir' => $documentRoot,
            'disallowdot' => 1,
        ];
        [$result, $providerApi] = $this->withAddonDomainCompatibility(
            $connection,
            'addaddondomain',
            $parameters
        );
        $this->audit->record($userId, $accountId, 'domain.add', 'success', 'domain', $domain, [
            'document_root' => $documentRoot,
            'internal_subdomain' => $subdomain,
            'provider_api' => $providerApi,
        ]);
        return ['domain' => $domain, 'document_root' => $documentRoot, 'internal_subdomain' => $subdomain, 'provider_api' => $providerApi, 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function remove(int $userId, int $accountId, string $domain): array
    {
        $domain = $this->domain($domain);
        $connection = $this->accounts->connection($userId, $accountId);
        [$addonDomains, $listApi] = $this->listAddonDomains($connection);
        $match = null;
        foreach ($addonDomains as $candidate) {
            if (strcasecmp((string) ($candidate['domain'] ?? ''), $domain) === 0) {
                $match = $candidate;
                break;
            }
        }
        if ($match === null) {
            throw new AppException('The addon domain was not found on this cPanel account.', 404, 'addon_domain_not_found', [], 'domains.overview');
        }
        $domainKey = trim((string) ($match['domainkey'] ?? ''));
        if ($domainKey === '') {
            $subdomain = trim((string) ($match['subdomain'] ?? ''));
            $rootDomain = trim((string) ($match['rootdomain'] ?? ''));
            if ($subdomain !== '' && $rootDomain !== '') {
                $domainKey = $subdomain . '_' . $rootDomain;
            }
        }
        if ($domainKey === '' || strlen($domainKey) > 320 || preg_match('/^[A-Za-z0-9_.-]+$/', $domainKey) !== 1) {
            throw new AppException('cPanel did not return a safe addon-domain mapping. Refresh capabilities and try again.', 409, 'addon_domain_mapping_missing', [], 'domains.overview');
        }
        [$result, $providerApi] = $this->withAddonDomainCompatibility($connection, 'deladdondomain', [
            'domain' => $domain,
            'subdomain' => $domainKey,
            'disallowdot' => 1,
        ]);
        $this->audit->record($userId, $accountId, 'domain.delete', 'success', 'domain', $domain, ['provider_api' => $providerApi, 'list_api' => $listApi]);
        return ['domain' => $domain, 'provider_api' => $providerApi, 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function addSubdomain(int $userId, int $accountId, string $subdomain, string $parentDomain, string $documentRoot): array
    {
        $subdomain = $this->label($subdomain);
        $parentDomain = $this->domain($parentDomain);
        $documentRoot = $this->documentRoot($documentRoot);
        $connection = $this->accounts->connection($userId, $accountId);
        $parameters = ['domain' => $subdomain, 'rootdomain' => $parentDomain, 'dir' => $documentRoot, 'disallowdot' => 1];
        [$result, $providerApi] = $this->withSubdomainCompatibility($connection, 'addsubdomain', $parameters);
        $fqdn = $subdomain . '.' . $parentDomain;
        $this->audit->record($userId, $accountId, 'domain.subdomain_add', 'success', 'domain', $fqdn, ['document_root' => $documentRoot, 'provider_api' => $providerApi]);
        return ['domain' => $fqdn, 'document_root' => $documentRoot, 'provider_api' => $providerApi, 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function removeSubdomain(int $userId, int $accountId, string $fqdn): array
    {
        $fqdn = $this->domain($fqdn);
        $connection = $this->accounts->connection($userId, $accountId);
        [$result, $providerApi] = $this->withSubdomainCompatibility($connection, 'delsubdomain', ['domain' => $this->subdomainDomainKey($fqdn), 'disallowdot' => 1]);
        $this->audit->record($userId, $accountId, 'domain.subdomain_delete', 'success', 'domain', $fqdn, ['provider_api' => $providerApi]);
        return ['domain' => $fqdn, 'provider_api' => $providerApi, 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function redirects(int $userId, int $accountId): array
    {
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Mime', 'list_redirects');
        return ['redirects' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function addRedirect(int $userId, int $accountId, string $domain, string $sourcePath, string $destination, int $status = 301, bool $wildcard = false): array
    {
        $domain = $this->domain($domain);
        $sourcePath = '/' . ltrim($sourcePath, '/');
        if (str_contains($sourcePath, '..') || strlen($sourcePath) > 1024 || !filter_var($destination, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($destination), 'https://')) {
            throw new AppException('Redirect paths must be safe and the destination must be an HTTPS URL.', 422, 'invalid_redirect', [], 'domains.redirects');
        }
        $status = in_array($status, [301, 302], true) ? $status : 301;
        $connection = $this->accounts->connection($userId, $accountId);
        $result = $this->cpanel->call($connection, 'Mime', 'add_redirect', [
            'domain' => $domain,
            'src' => $sourcePath,
            'redirect' => $destination,
            'type' => $status === 301 ? 'permanent' : 'temporary',
            'redirect_wildcard' => $wildcard ? 1 : 0,
            'redirect_www' => 0,
        ], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'domain.redirect_add', 'success', 'domain', $domain, ['source' => $sourcePath, 'destination' => $destination, 'status' => $status]);
        return ['cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function removeRedirect(int $userId, int $accountId, string $domain, string $sourcePath): array
    {
        $domain = $this->domain($domain);
        $sourcePath = '/' . ltrim($sourcePath, '/');
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Mime', 'delete_redirect', ['domain' => $domain, 'src' => $sourcePath], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'domain.redirect_delete', 'success', 'domain', $domain, ['source' => $sourcePath]);
        return ['cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function dnsZone(int $userId, int $accountId, string $domain): array
    {
        $domain = $this->domain($domain);
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'DNS', 'parse_zone', ['zone' => $domain]);
        return ['domain' => $domain, 'records' => $result['data']];
    }

    /** @param list<array<string,mixed>> $edits
     *  @return array<string,mixed>
     */
    public function editDnsZone(int $userId, int $accountId, string $domain, array $edits): array
    {
        $domain = $this->domain($domain);
        if ($edits === [] || count($edits) > 100) {
            throw new AppException('DNS changes must contain between 1 and 100 records.', 422, 'invalid_dns_changes', [], 'domains.dns');
        }
        $connection = $this->accounts->connection($userId, $accountId);
        $zone = $this->cpanel->call($connection, 'DNS', 'parse_zone', ['zone' => $domain]);
        $params = ['zone' => $domain, 'serial' => $this->dnsSerial($zone['data'])];
        $grouped = ['add' => [], 'edit' => [], 'remove' => []];
        foreach (array_values($edits) as $index => $edit) {
            if (!is_array($edit) || !in_array($edit['action'] ?? '', ['add', 'edit', 'remove'], true)) {
                throw new AppException('A DNS record change is invalid.', 422, 'invalid_dns_change', ['index' => $index], 'domains.dns');
            }
            $action = (string) $edit['action'];
            if ($action === 'remove') {
                $grouped['remove'][] = $this->dnsLineIndex($edit['line_index'] ?? null, $index);
                continue;
            }
            $allowed = ['action', 'line_index', 'dname', 'ttl', 'record_type', 'data'];
            if (array_diff(array_keys($edit), $allowed) !== []) {
                throw new AppException('A DNS record contains an unsupported field.', 422, 'invalid_dns_change', ['index' => $index], 'domains.dns');
            }
            $dname = trim((string) ($edit['dname'] ?? ''));
            $recordType = strtoupper(trim((string) ($edit['record_type'] ?? '')));
            $ttl = filter_var($edit['ttl'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2_147_483_647]]);
            $data = $edit['data'] ?? null;
            if ($dname === '' || strlen($dname) > 255 || preg_match('/[\x00\r\n]/', $dname) || preg_match('/^[A-Z][A-Z0-9]{0,15}$/', $recordType) !== 1 || $ttl === false || !is_array($data) || $data === [] || count($data) > 32) {
                throw new AppException('A DNS record name, type, TTL, or data value is invalid.', 422, 'invalid_dns_change', ['index' => $index], 'domains.dns');
            }
            $recordData = [];
            foreach ($data as $value) {
                if (!is_scalar($value) || strlen((string) $value) > 4096 || preg_match('/[\x00\r\n]/', (string) $value)) {
                    throw new AppException('A DNS record data value is invalid.', 422, 'invalid_dns_change', ['index' => $index], 'domains.dns');
                }
                $recordData[] = (string) $value;
            }
            $record = ['dname' => $dname, 'ttl' => (int) $ttl, 'record_type' => $recordType, 'data' => $recordData];
            if ($action === 'edit') {
                $record['line_index'] = $this->dnsLineIndex($edit['line_index'] ?? null, $index);
            }
            $grouped[$action][] = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        foreach ($grouped as $action => $values) {
            if ($values !== []) {
                $params[$action] = $values;
            }
        }
        $result = $this->cpanel->call($connection, 'DNS', 'mass_edit_zone', $params, 'POST', [], false);
        $this->audit->record($userId, $accountId, 'domain.dns_edit', 'success', 'domain', $domain, ['change_count' => count($edits)]);
        return ['cpanel' => $result['data']];
    }

    private function domain(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $domain = is_string($ascii) ? $ascii : $domain;
        }
        if (strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || !str_contains($domain, '.')) {
            throw new AppException('Enter a valid fully-qualified domain name.', 422, 'invalid_domain', [], 'domains.overview');
        }
        return $domain;
    }

    private function label(string $label): string
    {
        $label = strtolower(trim($label));
        if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label)) {
            throw new AppException('Enter a valid subdomain label.', 422, 'invalid_subdomain', [], 'domains.overview');
        }
        return $label;
    }

    private function documentRoot(string $root): string
    {
        $root = trim(str_replace('\\', '/', $root), '/');
        if ($root === '' || strlen($root) > 768 || str_contains('/' . $root . '/', '/../') || str_contains($root, "\0")) {
            throw new AppException('Document root is invalid.', 422, 'invalid_document_root', [], 'domains.overview');
        }
        return $root;
    }

    private function dnsLineIndex(mixed $value, int $changeIndex): int
    {
        $line = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($line === false) {
            throw new AppException('A DNS edit/remove operation requires a valid line_index.', 422, 'invalid_dns_change', ['index' => $changeIndex], 'domains.dns');
        }
        return (int) $line;
    }

    private function dnsSerial(mixed $payload): int
    {
        foreach ($this->asList($payload) as $record) {
            if (strtoupper((string) ($record['record_type'] ?? '')) !== 'SOA') {
                continue;
            }
            $encoded = $record['data_b64'] ?? [];
            if (!is_array($encoded)) {
                $encoded = [$encoded];
            }
            $decoded = [];
            foreach ($encoded as $value) {
                $plain = is_string($value) ? base64_decode($value, true) : false;
                if ($plain !== false) {
                    $parts = preg_split('/\s+/', trim($plain));
                    if (is_array($parts)) {
                        array_push($decoded, ...$parts);
                    }
                }
            }
            $candidate = $decoded[2] ?? null;
            if (is_string($candidate) && preg_match('/^[0-9]{1,18}$/', $candidate) === 1 && (int) $candidate > 0) {
                return (int) $candidate;
            }
            foreach ($decoded as $value) {
                if (preg_match('/^[0-9]{9,18}$/', $value) === 1 && (int) $value > 0) {
                    return (int) $value;
                }
            }
        }
        throw new AppException('cPanel did not return the DNS zone serial. Refresh the zone and try again.', 409, 'dns_serial_unavailable', [], 'domains.dns');
    }

    private function addonSubdomainLabel(string $domain): string
    {
        $label = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($domain)), '-');
        if ($label === '') {
            $label = 'addon';
        }
        if (strlen($label) > 54) {
            $label = rtrim(substr($label, 0, 54), '-') . '-' . substr(hash('sha256', $domain), 0, 8);
        }
        return $label;
    }

    private function subdomainDomainKey(string $fqdn): string
    {
        $separator = strpos($fqdn, '.');
        if ($separator === false) {
            throw new AppException('Enter a valid fully-qualified subdomain.', 422, 'invalid_subdomain', [], 'domains.overview');
        }
        return substr($fqdn, 0, $separator) . '_' . substr($fqdn, $separator + 1);
    }

    /** @param array{base_url:string,username:string,token:string} $connection
     *  @param array<string,scalar|null> $parameters
     *  @return array{0:array{data:mixed,metadata:array<string,mixed>,messages:list<string>,warnings:list<string>},1:string}
     */
    private function withAddonDomainCompatibility(array $connection, string $function, array $parameters): array
    {
        try {
            return [$this->cpanel->call($connection, 'AddonDomain', $function, $parameters, 'GET', [], false), 'uapi'];
        } catch (CpanelApiException $exception) {
            if (!$this->cpanel->isOperationUnavailable($exception)) {
                throw $exception;
            }
        }
        return [$this->cpanel->callLegacyApi2($connection, 'AddonDomain', $function, $parameters, false), 'api2_compatibility'];
    }

    /** @param array{base_url:string,username:string,token:string} $connection
     *  @return array{0:list<array<string,mixed>>,1:string}
     */
    private function listAddonDomains(array $connection): array
    {
        try {
            $result = $this->cpanel->call($connection, 'AddonDomain', 'listaddondomains');
            return [$this->asList($result['data']), 'uapi'];
        } catch (CpanelApiException $exception) {
            if (!$this->cpanel->isOperationUnavailable($exception)) {
                throw $exception;
            }
        }
        $result = $this->cpanel->callLegacyApi2($connection, 'AddonDomain', 'listaddondomains');
        return [$this->asList($result['data']), 'api2_compatibility'];
    }

    /** @param array{base_url:string,username:string,token:string} $connection
     *  @param array<string,scalar|null> $parameters
     *  @return array{0:array{data:mixed,metadata:array<string,mixed>,messages:list<string>,warnings:list<string>},1:string}
     */
    private function withSubdomainCompatibility(array $connection, string $function, array $parameters): array
    {
        try {
            return [$this->cpanel->call($connection, 'SubDomain', $function, $parameters, 'GET', [], false), 'uapi'];
        } catch (CpanelApiException $exception) {
            if (!$this->cpanel->isOperationUnavailable($exception)) {
                throw $exception;
            }
        }
        return [$this->cpanel->callLegacyApi2($connection, 'SubDomain', $function, $parameters, false), 'api2_compatibility'];
    }

    /** @return list<array<string,mixed>> */
    private function asList(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        return [$data];
    }
}
