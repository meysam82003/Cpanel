<?php

declare(strict_types=1);

namespace App\Cpanel;

use App\Core\Logger;
use App\Security\HostValidator;
use CURLFile;

final class UapiClient
{
    public function __construct(
        private readonly HostValidator $hostValidator,
        private readonly Logger $logger,
        private readonly int $connectTimeout = 8,
        private readonly int $requestTimeout = 30,
        private readonly int $maxResponseBytes = 16_777_216,
    ) {
    }

    /**
     * @param array{base_url:string,username:string,token:string} $connection
     * @param array<string, scalar|list<scalar>|null> $parameters
     * @param array<string, array{path:string,mime?:string,name?:string}> $files
     * @return array{data:mixed,metadata:array<string,mixed>,messages:list<string>,warnings:list<string>}
     */
    public function call(
        array $connection,
        string $module,
        string $function,
        array $parameters = [],
        string $method = 'GET',
        array $files = [],
        bool $idempotent = true,
    ): array {
        $this->assertIdentifier($module, 'module');
        $this->assertIdentifier($function, 'function');
        $validated = $this->hostValidator->validate($connection['base_url']);
        $attempts = $idempotent ? 2 : 1;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            foreach ($validated['ips'] as $ip) {
                try {
                    return $this->perform($validated, $ip, $connection, $module, $function, $parameters, $method, $files);
                } catch (CpanelApiException $exception) {
                    $lastError = $exception;
                    if (!in_array($exception->safeCode, ['cpanel_network_error', 'cpanel_timeout'], true)) {
                        throw $exception;
                    }
                }
            }
            if ($attempt < $attempts) {
                usleep(150_000 * $attempt);
                $validated = $this->hostValidator->validate($connection['base_url']);
            }
        }
        throw $lastError ?? new CpanelApiException('The cPanel request failed.', 'cpanel_request_failed');
    }

    /** @param array{base_url:string,username:string,token:string} $connection
     *  @return array<string, mixed>
     */
    public function testConnection(array $connection): array
    {
        $result = $this->call($connection, 'Variables', 'get_user_information');
        $data = is_array($result['data']) ? $result['data'] : [];
        if ($data === []) {
            throw new CpanelApiException('cPanel accepted the request but returned no account information.', 'cpanel_empty_account_info');
        }
        return $data;
    }

    /**
     * Streams a cPanel file download to a local file without retaining file contents in memory.
     * The caller must create the destination inside an application-controlled directory.
     *
     * @param array{base_url:string,username:string,token:string} $connection
     * @return array{bytes:int,sha256:string,content_type:?string}
     */
    public function downloadTo(array $connection, string $remotePath, string $destination, int $maxBytes): array
    {
        if ($remotePath === '' || str_contains($remotePath, "\0") || str_contains($remotePath, "\r") || str_contains($remotePath, "\n")) {
            throw new CpanelApiException('The requested remote file path is invalid.', 'invalid_remote_path', [], 422);
        }
        if ($maxBytes < 1 || $maxBytes > 1_073_741_824) {
            throw new CpanelApiException('The download size policy is invalid.', 'invalid_download_limit', [], 500);
        }
        $parent = dirname($destination);
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new CpanelApiException('The secure download directory is unavailable.', 'download_storage_unavailable', [], 500);
        }
        $validated = $this->hostValidator->validate($connection['base_url']);
        $url = $validated['base_url'] . '/download?' . http_build_query(['file' => $remotePath], '', '&', PHP_QUERY_RFC3986);
        $last = null;
        foreach ($validated['ips'] as $ip) {
            $handle = fopen($destination, 'w+b');
            if ($handle === false) {
                throw new CpanelApiException('The secure download file could not be created.', 'download_storage_unavailable', [], 500);
            }
            $bytes = 0;
            $overflow = false;
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => ['Accept: application/octet-stream', 'Authorization: cpanel ' . $connection['username'] . ':' . $connection['token']],
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT => max($this->requestTimeout, 120),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$validated['host'] . ':' . $validated['port'] . ':' . $ip],
                CURLOPT_USERAGENT => 'TelegramCpanelManager/1.0',
                CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use ($handle, $maxBytes, &$bytes, &$overflow): int {
                    if ($bytes + strlen($chunk) > $maxBytes) {
                        $overflow = true;
                        return 0;
                    }
                    $written = fwrite($handle, $chunk);
                    if ($written === false) {
                        return 0;
                    }
                    $bytes += $written;
                    return $written;
                },
            ]);
            $ok = curl_exec($curl);
            $errno = curl_errno($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
            curl_close($curl);
            fclose($handle);
            if ($overflow) {
                @unlink($destination);
                throw new CpanelApiException('The remote file exceeds the configured download limit.', 'download_too_large', ['max_bytes' => $maxBytes], 413);
            }
            if ($ok !== false && $errno === 0 && $status >= 200 && $status < 300) {
                return ['bytes' => $bytes, 'sha256' => hash_file('sha256', $destination), 'content_type' => is_string($contentType) ? $contentType : null];
            }
            @unlink($destination);
            $last = new CpanelApiException(
                $errno === CURLE_OPERATION_TIMEDOUT ? 'The cPanel file download timed out.' : 'The cPanel file could not be downloaded securely.',
                $errno === CURLE_OPERATION_TIMEDOUT ? 'cpanel_timeout' : ($status === 401 || $status === 403 ? 'cpanel_auth_failed' : 'cpanel_download_failed'),
                ['curl_errno' => $errno, 'http_status' => $status]
            );
            if (!in_array($last->safeCode, ['cpanel_timeout', 'cpanel_download_failed'], true)) {
                throw $last;
            }
        }
        throw $last ?? new CpanelApiException('The cPanel file download failed.', 'cpanel_download_failed');
    }

    /**
     * Compatibility bridge used only when cPanel documents no UAPI equivalent.
     * API 2 calls remain centralized here so TLS, DNS pinning, limits and error handling stay identical.
     *
     * @param array{base_url:string,username:string,token:string} $connection
     * @param array<string,scalar|null> $parameters
     * @return array{data:mixed,metadata:array<string,mixed>,messages:list<string>,warnings:list<string>}
     */
    public function callLegacyApi2(array $connection, string $module, string $function, array $parameters = []): array
    {
        $this->assertIdentifier($module, 'module');
        $this->assertIdentifier($function, 'function');
        $validated = $this->hostValidator->validate($connection['base_url']);
        $query = $parameters + [
            'cpanel_jsonapi_user' => $connection['username'],
            'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $function,
        ];
        $url = $validated['base_url'] . '/json-api/cpanel?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $last = null;
        foreach ($validated['ips'] as $ip) {
            try {
                return $this->performLegacy($validated, $ip, $connection, $url, $module, $function);
            } catch (CpanelApiException $exception) {
                $last = $exception;
                if (!in_array($exception->safeCode, ['cpanel_network_error', 'cpanel_timeout'], true)) {
                    throw $exception;
                }
            }
        }
        throw $last ?? new CpanelApiException('The cPanel compatibility request failed.', 'cpanel_request_failed');
    }

    /**
     * @param array{base_url:string,host:string,port:int,ips:list<string>} $validated
     * @param array{base_url:string,username:string,token:string} $connection
     * @param array<string, scalar|list<scalar>|null> $parameters
     * @param array<string, array{path:string,mime?:string,name?:string}> $files
     * @return array{data:mixed,metadata:array<string,mixed>,messages:list<string>,warnings:list<string>}
     */
    private function perform(array $validated, string $ip, array $connection, string $module, string $function, array $parameters, string $method, array $files): array
    {
        $method = strtoupper($method);
        $url = $validated['base_url'] . '/execute/' . rawurlencode($module) . '/' . rawurlencode($function);
        $body = null;
        $headers = ['Accept: application/json', 'Authorization: cpanel ' . $connection['username'] . ':' . $connection['token']];
        if ($method === 'GET' && $parameters !== []) {
            $url .= '?' . $this->buildQuery($parameters);
        } elseif ($method !== 'GET') {
            if ($files !== []) {
                $body = $parameters;
                foreach ($files as $field => $file) {
                    if (!is_file($file['path']) || !is_readable($file['path'])) {
                        throw new CpanelApiException('The temporary upload file is unavailable.', 'upload_temp_missing', ['field' => $field], 422);
                    }
                    $body[$field] = new CURLFile($file['path'], $file['mime'] ?? 'application/octet-stream', $file['name'] ?? basename($file['path']));
                }
            } else {
                $body = $this->buildQuery($parameters);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }

        $response = '';
        $overflow = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$validated['host'] . ':' . $validated['port'] . ':' . $ip],
            CURLOPT_USERAGENT => 'TelegramCpanelManager/1.0',
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$response, &$overflow): int {
                if (strlen($response) + strlen($chunk) > $this->maxResponseBytes) {
                    $overflow = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        $started = microtime(true);
        $ok = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $duration = (int) round((microtime(true) - $started) * 1000);

        if ($overflow) {
            throw new CpanelApiException('The cPanel response exceeded the configured safety limit.', 'cpanel_response_too_large');
        }
        if ($ok === false || $errno !== 0) {
            $code = $errno === CURLE_OPERATION_TIMEDOUT ? 'cpanel_timeout' : 'cpanel_network_error';
            $this->logger->log('warning', 'cPanel transport failure', ['code' => $code, 'curl_errno' => $errno, 'duration_ms' => $duration, 'host' => $validated['host']]);
            throw new CpanelApiException(
                $code === 'cpanel_timeout' ? 'The cPanel request timed out.' : 'The cPanel server could not be reached securely.',
                $code,
                ['curl_errno' => $errno, 'transport_error' => $error]
            );
        }
        if ($status === 401 || $status === 403) {
            throw new CpanelApiException('cPanel rejected the API token or the requested permission.', 'cpanel_auth_failed', ['http_status' => $status], 401);
        }
        if ($status < 200 || $status >= 300) {
            throw new CpanelApiException('cPanel returned an unexpected HTTP status.', 'cpanel_http_error', ['http_status' => $status]);
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CpanelApiException('cPanel returned malformed JSON.', 'cpanel_invalid_json', ['http_status' => $status]);
        }
        $result = is_array($decoded) && isset($decoded['result']) && is_array($decoded['result']) ? $decoded['result'] : $decoded;
        if (!is_array($result)) {
            throw new CpanelApiException('cPanel returned an invalid response envelope.', 'cpanel_invalid_response');
        }
        $errors = $this->stringList($result['errors'] ?? []);
        if ((int) ($result['status'] ?? 0) !== 1 || $errors !== []) {
            $this->logger->log('warning', 'cPanel operation rejected', ['module' => $module, 'function' => $function, 'errors' => $errors]);
            throw new CpanelApiException(
                'cPanel did not complete the requested operation. Check the feature permission and input, then try again.',
                'cpanel_operation_failed',
                ['module' => $module, 'function' => $function, 'errors' => $errors]
            );
        }
        return [
            'data' => $result['data'] ?? [],
            'metadata' => is_array($result['metadata'] ?? null) ? $result['metadata'] : [],
            'messages' => $this->stringList($result['messages'] ?? []),
            'warnings' => $this->stringList($result['warnings'] ?? []),
        ];
    }

    /** @param array{base_url:string,host:string,port:int,ips:list<string>} $validated
     *  @param array{base_url:string,username:string,token:string} $connection
     *  @return array{data:mixed,metadata:array<string,mixed>,messages:list<string>,warnings:list<string>}
     */
    private function performLegacy(array $validated, string $ip, array $connection, string $url, string $module, string $function): array
    {
        $response = '';
        $overflow = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: cpanel ' . $connection['username'] . ':' . $connection['token']],
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$validated['host'] . ':' . $validated['port'] . ':' . $ip],
            CURLOPT_USERAGENT => 'TelegramCpanelManager/1.0',
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$response, &$overflow): int {
                if (strlen($response) + strlen($chunk) > $this->maxResponseBytes) {
                    $overflow = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($overflow) {
            throw new CpanelApiException('The cPanel response exceeded the configured safety limit.', 'cpanel_response_too_large');
        }
        if ($ok === false || $errno !== 0) {
            throw new CpanelApiException($errno === CURLE_OPERATION_TIMEDOUT ? 'The cPanel request timed out.' : 'The cPanel server could not be reached securely.', $errno === CURLE_OPERATION_TIMEDOUT ? 'cpanel_timeout' : 'cpanel_network_error', ['curl_errno' => $errno]);
        }
        if ($status === 401 || $status === 403) {
            throw new CpanelApiException('cPanel rejected the API token or compatibility operation.', 'cpanel_auth_failed', ['http_status' => $status], 401);
        }
        if ($status < 200 || $status >= 300) {
            throw new CpanelApiException('cPanel returned an unexpected HTTP status.', 'cpanel_http_error', ['http_status' => $status]);
        }
        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CpanelApiException('cPanel returned malformed JSON.', 'cpanel_invalid_json');
        }
        $result = is_array($decoded) && isset($decoded['cpanelresult']) && is_array($decoded['cpanelresult']) ? $decoded['cpanelresult'] : null;
        if ($result === null) {
            throw new CpanelApiException('cPanel returned an invalid compatibility response.', 'cpanel_invalid_response');
        }
        $success = (int) ($result['event']['result'] ?? 0) === 1;
        $error = trim((string) ($result['error'] ?? $result['reason'] ?? ''));
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hasOutcome = array_key_exists('status', $item) || array_key_exists('result', $item);
            $itemSucceeded = !$hasOutcome || (int) ($item['status'] ?? $item['result'] ?? 0) === 1;
            if (!$itemSucceeded) {
                $success = false;
                $error = trim((string) ($item['reason'] ?? $item['statusmsg'] ?? $error));
                break;
            }
        }
        if (!$success || $error !== '') {
            $this->logger->log('warning', 'cPanel API 2 compatibility operation rejected', ['module' => $module, 'function' => $function]);
            throw new CpanelApiException('cPanel did not complete the compatibility operation. Check the feature permission and input, then try again.', 'cpanel_operation_failed', ['module' => $module, 'function' => $function, 'provider_reason' => $error]);
        }
        return ['data' => $data, 'metadata' => ['legacy_api2' => true], 'messages' => [], 'warnings' => ['This operation used the documented API 2 compatibility fallback.']];
    }

    /**
     * Returns true only when cPanel indicates that the requested UAPI route is
     * absent on that server version. Permission, validation, quota and business
     * errors must never be retried through API 2 because that could bypass the
     * provider's intended failure semantics.
     */
    public function isOperationUnavailable(CpanelApiException $exception): bool
    {
        $status = (int) ($exception->context['http_status'] ?? 0);
        if ($exception->safeCode === 'cpanel_http_error' && in_array($status, [404, 405, 501], true)) {
            return true;
        }
        if ($exception->safeCode !== 'cpanel_operation_failed') {
            return false;
        }

        $errors = $exception->context['errors'] ?? [];
        $message = strtolower(implode(' ', is_array($errors) ? array_map('strval', $errors) : [(string) $errors]));
        if ($message === '') {
            return false;
        }

        foreach ([
            '/api\s+(?:function|method).*?(?:could not be found|does not exist|is not available|is unavailable|unknown)/i',
            '/(?:unknown|invalid)\s+(?:uapi\s+)?(?:module|function|method)/i',
            '/(?:module|function|method).*?not\s+(?:found|implemented|supported|available)/i',
            '/no\s+such\s+(?:uapi\s+)?(?:module|function|method)/i',
        ] as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if ($value === null || $value === false || $value === '') {
            return [];
        }
        if (is_string($value)) {
            return [$value];
        }
        return array_values(array_map('strval', is_array($value) ? array_filter($value, static fn ($item): bool => is_scalar($item)) : []));
    }

    /** @param array<string, scalar|list<scalar>|null> $parameters */
    private function buildQuery(array $parameters): string
    {
        $pairs = [];
        foreach ($parameters as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (!is_scalar($item)) {
                        throw new CpanelApiException('A cPanel list parameter contains an invalid value.', 'cpanel_invalid_parameter', ['parameter' => $name], 422);
                    }
                    $pairs[] = rawurlencode($name) . '=' . rawurlencode((string) $item);
                }
                continue;
            }
            if ($value !== null && !is_scalar($value)) {
                throw new CpanelApiException('A cPanel parameter contains an invalid value.', 'cpanel_invalid_parameter', ['parameter' => $name], 422);
            }
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value === null ? '' : (string) $value);
        }
        return implode('&', $pairs);
    }

    private function assertIdentifier(string $value, string $type): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', $value)) {
            throw new CpanelApiException("Invalid cPanel {$type} identifier.", 'cpanel_invalid_operation', [], 500);
        }
    }
}
