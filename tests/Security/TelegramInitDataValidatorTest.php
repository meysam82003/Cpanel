<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\AppException;
use App\Security\TelegramInitDataValidator;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class TelegramInitDataValidatorTest extends TestCase
{
    use SqliteTestDatabase;

    private const BOT_TOKEN = '123456:' . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function testValidatesTelegramSignatureFreshnessIdentityAndReplay(): void
    {
        $database = $this->sqlite('CREATE TABLE replay_nonces (nonce_hash TEXT PRIMARY KEY, user_id INTEGER, expires_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);');
        $validator = new TelegramInitDataValidator($database, self::BOT_TOKEN, 900);
        $payload = $this->signedPayload(['id' => 99887766, 'first_name' => 'Test'], time());
        $result = $validator->validate($payload);
        self::assertSame(99887766, $result['user']['id']);
        try {
            $validator->validate($payload);
            self::fail('initData replay was accepted.');
        } catch (AppException $exception) {
            self::assertSame('init_data_replay', $exception->safeCode);
        }
    }

    public function testRejectsTamperedExpiredAndNonPositiveIdentity(): void
    {
        $validator = new TelegramInitDataValidator($this->sqlite('CREATE TABLE replay_nonces (nonce_hash TEXT PRIMARY KEY, user_id INTEGER, expires_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);'), self::BOT_TOKEN, 60);
        $tampered = $this->signedPayload(['id' => 55, 'first_name' => 'Before'], time()) . '&extra=after-signature';
        $this->assertCode('invalid_init_data', fn () => $validator->validate($tampered));
        $this->assertCode('expired_init_data', fn () => $validator->validate($this->signedPayload(['id' => 55], time() - 120)));
        $this->assertCode('invalid_init_data', fn () => $validator->validate($this->signedPayload(['id' => 0], time())));
    }

    /** @param array<string,mixed> $user */
    private function signedPayload(array $user, int $authDate): string
    {
        $data = [
            'auth_date' => (string) $authDate,
            'query_id' => 'AAE-test-query-' . $authDate,
            'user' => json_encode($user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
        ksort($data, SORT_STRING);
        $check = implode("\n", array_map(static fn (string $key, string $value): string => $key . '=' . $value, array_keys($data), array_values($data)));
        $secret = hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true);
        $data['hash'] = hash_hmac('sha256', $check, $secret);
        return http_build_query($data, '', '&', PHP_QUERY_RFC3986);
    }

    private function assertCode(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected authentication error was not thrown.');
        } catch (AppException $exception) {
            self::assertSame($code, $exception->safeCode);
        }
    }
}
