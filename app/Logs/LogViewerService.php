<?php

declare(strict_types=1);

namespace App\Logs;

use App\Accounts\AccountRepository;
use App\Core\AppException;
use App\Cpanel\UapiClient;
use App\Security\PathGuard;

final class LogViewerService
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly PathGuard $paths,
        private readonly string $tempDirectory,
        private readonly int $maxDownloadBytes = 104_857_600,
    ) {
    }

    /** @return list<array{name:string,path:string}> */
    public function discover(int $userId, int $accountId): array
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $connection = $this->accounts->connection($userId, $accountId);
        $candidates = [
            $root . '/public_html/error_log',
            $root . '/logs/error_log',
            $root . '/error_log',
        ];
        $found = [];
        foreach ($candidates as $candidate) {
            try {
                $result = $this->cpanel->call($connection, 'Fileman', 'get_file_information', ['path' => $candidate]);
                $item = $this->first($result['data']);
                if ((int) ($item['exists'] ?? 0) === 1 && !in_array(strtolower((string) ($item['type'] ?? 'file')), ['dir', 'directory'], true)) {
                    $found[] = ['name' => basename($candidate), 'path' => $candidate];
                }
            } catch (\Throwable) {
                // A missing or forbidden conventional log is omitted; user-selected logs remain available.
            }
        }
        return $found;
    }

    /** @return array{path:string,lines:list<string>,truncated:bool} */
    public function tail(int $userId, int $accountId, string $remotePath, int $lines = 100, ?string $search = null): array
    {
        [$connection, $safePath] = $this->context($userId, $accountId, $remotePath);
        $lines = in_array($lines, [50, 100], true) ? $lines : 100;
        if ($search !== null) {
            $search = trim(mb_substr($search, 0, 100));
            if ($search === '') {
                $search = null;
            }
        }
        $directory = rtrim($this->tempDirectory, '/');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new AppException('Secure log temporary storage is unavailable.', 500, 'log_storage_unavailable');
        }
        $local = $directory . '/log-' . bin2hex(random_bytes(16));
        try {
            $metadata = $this->cpanel->downloadTo($connection, $safePath, $local, $this->maxDownloadBytes);
            $selected = $search === null ? $this->readTail($local, $lines) : $this->searchLines($local, $search, $lines);
            return ['path' => $safePath, 'lines' => $selected, 'truncated' => $metadata['bytes'] >= $this->maxDownloadBytes];
        } finally {
            @unlink($local);
        }
    }

    /** @return array{0:array{base_url:string,username:string,token:string},1:string} */
    private function context(int $userId, int $accountId, string $path): array
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $safePath = $this->paths->normalize($path, $root);
        $name = strtolower(basename($safePath));
        if (!str_ends_with($name, '.log') && $name !== 'error_log') {
            throw new AppException('Only explicitly selected log files can be viewed here.', 415, 'unsupported_log_file', [], 'logs.overview');
        }
        return [$this->accounts->connection($userId, $accountId), $safePath];
    }

    /** @return list<string> */
    private function readTail(string $file, int $lineCount): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new AppException('The downloaded log could not be read.', 500, 'log_read_failed');
        }
        $buffer = '';
        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);
        while ($position > 0 && substr_count($buffer, "\n") <= $lineCount) {
            $read = min(8192, $position);
            $position -= $read;
            fseek($handle, $position);
            $buffer = (string) fread($handle, $read) . $buffer;
            if (strlen($buffer) > 2_097_152) {
                break;
            }
        }
        fclose($handle);
        $all = preg_split('/\r\n|\r|\n/', trim($buffer));
        return array_slice(is_array($all) ? $all : [], -$lineCount);
    }

    /** @return list<string> */
    private function searchLines(string $file, string $needle, int $limit): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new AppException('The downloaded log could not be read.', 500, 'log_read_failed');
        }
        $matches = [];
        while (($line = fgets($handle, 262144)) !== false) {
            if (mb_stripos($line, $needle) !== false) {
                $matches[] = rtrim($line, "\r\n");
                if (count($matches) > $limit) {
                    array_shift($matches);
                }
            }
        }
        fclose($handle);
        return $matches;
    }

    /** @return array<string,mixed> */
    private function first(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (array_is_list($data)) {
            return is_array($data[0] ?? null) ? $data[0] : [];
        }
        return $data;
    }
}
