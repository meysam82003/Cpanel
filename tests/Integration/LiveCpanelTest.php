<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Logger;
use App\Cpanel\UapiClient;
use App\Security\HostValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('live-cpanel')]
final class LiveCpanelTest extends TestCase
{
    private UapiClient $client;
    /** @var array{base_url:string,username:string,token:string} */
    private array $connection;

    protected function setUp(): void
    {
        if (getenv('RUN_LIVE_CPANEL_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_LIVE_CPANEL_TESTS=1 and TEST_CPANEL_* for live provider tests.');
        }
        $url = (string) getenv('TEST_CPANEL_URL');
        $username = (string) getenv('TEST_CPANEL_USERNAME');
        $token = (string) getenv('TEST_CPANEL_TOKEN');
        if ($url === '' || $username === '' || $token === '') {
            self::markTestSkipped('TEST_CPANEL_URL, TEST_CPANEL_USERNAME, and TEST_CPANEL_TOKEN are required.');
        }
        $this->connection = ['base_url' => $url, 'username' => $username, 'token' => $token];
        $this->client = new UapiClient(new HostValidator(getenv('TEST_CPANEL_ALLOW_PRIVATE') === '1', [2083, 443]), new Logger(sys_get_temp_dir() . '/tcm-live-test-logs'));
    }

    public function testOfficialAccountAndFileListingEndpoints(): void
    {
        $info = $this->client->testConnection($this->connection);
        self::assertNotEmpty($info['user'] ?? $info['username'] ?? null);
        $root = rtrim((string) ($info['homedir'] ?? $info['home'] ?? '.'), '/');
        $listing = $this->client->call($this->connection, 'Fileman', 'list_files', ['dir' => $root, 'limit' => 5]);
        self::assertIsArray($listing['data']);
    }

    public function testIsolatedFileCreateReadCopyDeleteFlowWhenExplicitlyEnabled(): void
    {
        if (getenv('RUN_DESTRUCTIVE_CPANEL_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DESTRUCTIVE_CPANEL_TESTS=1 to test an isolated temporary cPanel file lifecycle.');
        }
        $info = $this->client->testConnection($this->connection);
        $root = rtrim((string) ($info['homedir'] ?? $info['home'] ?? ''), '/');
        self::assertNotSame('', $root);
        $nonce = bin2hex(random_bytes(8));
        $source = '.tcm-acceptance-' . $nonce . '.txt';
        $copy = '.tcm-acceptance-' . $nonce . '-copy.txt';
        try {
            $this->client->call($this->connection, 'Fileman', 'save_file_content', ['dir' => $root, 'file' => $source, 'content' => 'tcm-' . $nonce, 'from_charset' => 'UTF-8', 'to_charset' => 'UTF-8'], 'POST', [], false);
            $read = $this->client->call($this->connection, 'Fileman', 'get_file_content', ['dir' => $root, 'file' => $source]);
            self::assertStringContainsString($nonce, json_encode($read['data'], JSON_THROW_ON_ERROR));
            $this->client->call($this->connection, 'Fileman', 'copy_file', ['source' => $root . '/' . $source, 'destination' => $root . '/' . $copy], 'GET', [], false);
        } finally {
            foreach ([$source, $copy] as $file) {
                try {
                    $this->client->call($this->connection, 'Fileman', 'delete_file', ['path' => $root . '/' . $file], 'GET', [], false);
                } catch (\Throwable) {
                }
            }
        }
    }
}
