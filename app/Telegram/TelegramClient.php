<?php

declare(strict_types=1);

namespace App\Telegram;

use CURLFile;

final class TelegramClient
{
    public function __construct(
        private readonly string $token,
        private readonly int $timeout = 30,
    ) {
        if (!preg_match('/^\d{6,12}:[A-Za-z0-9_-]{30,}$/', $token)) {
            throw new TelegramApiException('The Telegram bot token format is invalid.', 'invalid_bot_token', [], 422);
        }
    }

    /** @param array<string,mixed> $parameters
     *  @return mixed
     */
    public function call(string $method, array $parameters = []): mixed
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/', $method)) {
            throw new TelegramApiException('Invalid Telegram API method.', 'invalid_telegram_method', [], 500);
        }
        $curl = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $this->normalizeParameters($parameters),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'TelegramCpanelManager/1.0',
        ]);
        $response = curl_exec($curl);
        $errorNumber = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($response === false || $errorNumber !== 0) {
            throw new TelegramApiException('Telegram could not be reached securely.', 'telegram_network_error', ['curl_errno' => $errorNumber]);
        }
        try {
            $decoded = json_decode($response, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TelegramApiException('Telegram returned an invalid response.', 'telegram_invalid_response', ['http_status' => $status]);
        }
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $description = is_array($decoded) ? (string) ($decoded['description'] ?? 'Telegram rejected the request.') : 'Telegram rejected the request.';
            throw new TelegramApiException($description, 'telegram_api_rejected', ['http_status' => $status, 'error_code' => $decoded['error_code'] ?? null], $status === 401 ? 401 : 502);
        }
        return $decoded['result'] ?? true;
    }

    /** @return array{bytes:int,file_path:string} */
    public function downloadFile(string $fileId, string $destination, int $maxBytes): array
    {
        if ($fileId === '' || strlen($fileId) > 512 || $maxBytes < 1 || $maxBytes > 104_857_600) {
            throw new TelegramApiException('Telegram file download parameters are invalid.', 'telegram_file_invalid', [], 422);
        }
        $metadata = $this->call('getFile', ['file_id' => $fileId]);
        $path = is_array($metadata) ? (string) ($metadata['file_path'] ?? '') : '';
        if ($path === '' || strlen($path) > 1024 || str_contains($path, '..') || !preg_match('#^[A-Za-z0-9_./-]+$#', $path)) {
            throw new TelegramApiException('Telegram returned an invalid file path.', 'telegram_file_invalid');
        }
        $parent = dirname($destination);
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new TelegramApiException('Temporary Telegram file storage is unavailable.', 'upload_storage_unavailable');
        }
        $handle = fopen($destination, 'w+b');
        if ($handle === false) {
            throw new TelegramApiException('Temporary Telegram file could not be created.', 'upload_storage_unavailable');
        }
        $bytes = 0;
        $overflow = false;
        $curl = curl_init('https://api.telegram.org/file/bot' . $this->token . '/' . $path);
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => max(60, $this->timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
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
        curl_close($curl);
        fclose($handle);
        if ($overflow) {
            @unlink($destination);
            throw new TelegramApiException('The Telegram document exceeds the configured upload limit.', 'upload_size_exceeded', ['limit' => $maxBytes], 413);
        }
        if ($ok === false || $errno !== 0 || $status < 200 || $status >= 300) {
            @unlink($destination);
            throw new TelegramApiException('Telegram document download failed.', $errno === CURLE_OPERATION_TIMEDOUT ? 'telegram_network_error' : 'telegram_file_download_failed', ['http_status' => $status, 'curl_errno' => $errno]);
        }
        @chmod($destination, 0600);
        return ['bytes' => $bytes, 'file_path' => $path];
    }

    /** @param array<string,mixed> $parameters
     *  @return array<string,mixed>
     */
    private function normalizeParameters(array $parameters): array
    {
        foreach ($parameters as $key => $value) {
            if (is_array($value) && !$value instanceof CURLFile) {
                $parameters[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } elseif (is_bool($value)) {
                $parameters[$key] = $value ? 'true' : 'false';
            }
        }
        return $parameters;
    }
}
