<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\AppException;
use App\Core\Database;

final class TelegramInitDataValidator
{
    public function __construct(
        private readonly Database $database,
        private readonly string $botToken,
        private readonly int $ttlSeconds = 900,
    ) {
    }

    /** @return array{user:array<string,mixed>,auth_date:int,query_id:?string} */
    public function validate(string $initData): array
    {
        if ($initData === '' || strlen($initData) > 16384) {
            throw new AppException('Telegram authentication data is missing or too large.', 401, 'invalid_init_data');
        }
        parse_str($initData, $data);
        $hash = isset($data['hash']) && is_string($data['hash']) ? strtolower($data['hash']) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new AppException('Telegram authentication signature is missing.', 401, 'invalid_init_data');
        }
        unset($data['hash']);
        ksort($data, SORT_STRING);
        $pairs = [];
        foreach ($data as $key => $value) {
            if (!is_string($value)) {
                throw new AppException('Telegram authentication data is malformed.', 401, 'invalid_init_data');
            }
            $pairs[] = $key . '=' . $value;
        }
        $checkString = implode("\n", $pairs);
        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $calculated = hash_hmac('sha256', $checkString, $secretKey);
        if (!hash_equals($calculated, $hash)) {
            throw new AppException('Telegram authentication signature is invalid.', 401, 'invalid_init_data', [], 'security.miniapp-auth');
        }

        $authDate = filter_var($data['auth_date'] ?? null, FILTER_VALIDATE_INT);
        if ($authDate === false || $authDate > time() + 30 || $authDate < time() - $this->ttlSeconds) {
            throw new AppException('Telegram authentication data has expired.', 401, 'expired_init_data', [], 'security.miniapp-auth');
        }
        $user = json_decode((string) ($data['user'] ?? ''), true);
        if (!is_array($user) || !isset($user['id']) || filter_var($user['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new AppException('Telegram user identity is invalid.', 401, 'invalid_init_data');
        }

        $replayHash = hash('sha256', $hash . '|' . ($data['query_id'] ?? '') . '|' . $authDate);
        try {
            $this->database->execute(
                'INSERT INTO replay_nonces (nonce_hash, expires_at) VALUES (?, ?)',
                [$replayHash, gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds)]
            );
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new AppException('This Telegram authentication payload has already been used.', 401, 'init_data_replay');
            }
            throw $exception;
        }

        return ['user' => $user, 'auth_date' => (int) $authDate, 'query_id' => isset($data['query_id']) ? (string) $data['query_id'] : null];
    }
}
