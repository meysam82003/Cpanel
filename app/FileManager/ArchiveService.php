<?php

declare(strict_types=1);

namespace App\FileManager;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Plans\PlanGuard;
use App\Queue\QueueService;
use App\Security\PathGuard;

final class ArchiveService
{
    /** @var array<string,list<string>> */
    private const FORMAT_SUFFIXES = [
        'zip' => ['.zip'],
        'tar.gz' => ['.tar.gz', '.tgz'],
        'tar.bz2' => ['.tar.bz2', '.tbz2'],
        'tar' => ['.tar'],
        'gz' => ['.gz'],
        'bz2' => ['.bz2'],
    ];

    public function __construct(
        private readonly Database $database,
        private readonly AccountRepository $accounts,
        private readonly PlanGuard $plans,
        private readonly PathGuard $paths,
        private readonly QueueService $queue,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param list<string> $sources
     *  @return array{job_id:int,status:string,operation:string,destination:string,format:string}
     */
    public function enqueueCreate(int $userId, int $accountId, array $sources, string $destination, string $format): array
    {
        $request = $this->validateCreate($userId, $accountId, ['sources' => $sources, 'destination' => $destination, 'format' => $format]);
        $this->plans->dailyOperation($userId);
        $fingerprint = $this->fingerprint('create', $userId, $accountId, $request);
        [$jobId, $created] = $this->database->transaction(function (Database $database) use ($userId, $accountId, $request, $fingerprint): array {
            $jobId = $this->queue->dispatch('file.archive_create', $userId, $accountId, $request, $fingerprint, 'default', 1);
            $state = $database->one('SELECT job_id, user_id, account_id FROM file_archive_jobs WHERE job_id = ? FOR UPDATE', [$jobId]);
            $created = false;
            if ($state === null) {
                $database->execute(
                    "INSERT INTO file_archive_jobs (job_id, user_id, account_id, operation, sources_json, archive_path, destination, format, collision_policy, status) VALUES (?, ?, ?, 'create', ?, ?, ?, ?, 'reject', 'queued')",
                    [$jobId, $userId, $accountId, json_encode($request['sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $request['destination'], $request['destination'], $request['format']]
                );
                $created = true;
            } elseif ((int) $state['user_id'] !== $userId || (int) $state['account_id'] !== $accountId) {
                throw new AppException('Archive job ownership is invalid.', 403, 'archive_job_forbidden', [], 'security.idor');
            }
            return [$jobId, $created];
        });
        if ($created) {
            $this->audit->record($userId, $accountId, 'file.archive_queue', 'success', 'archive', $request['destination'], ['job_id' => $jobId, 'operation' => 'create', 'format' => $request['format'], 'source_count' => count($request['sources'])]);
        }
        return ['job_id' => $jobId, 'status' => 'queued', 'operation' => 'create', 'destination' => $request['destination'], 'format' => $request['format']];
    }

    /** @return array{job_id:int,status:string,operation:string,archive:string,destination:string,collision:string} */
    public function enqueueExtract(int $userId, int $accountId, string $archive, string $destination, string $collision = 'reject'): array
    {
        $request = $this->validateExtract($userId, $accountId, ['archive' => $archive, 'destination' => $destination, 'collision' => $collision]);
        $this->plans->dailyOperation($userId);
        $fingerprint = $this->fingerprint('extract', $userId, $accountId, $request);
        [$jobId, $created] = $this->database->transaction(function (Database $database) use ($userId, $accountId, $request, $fingerprint): array {
            $jobId = $this->queue->dispatch('file.archive_extract', $userId, $accountId, $request, $fingerprint, 'default', 1);
            $state = $database->one('SELECT job_id, user_id, account_id FROM file_archive_jobs WHERE job_id = ? FOR UPDATE', [$jobId]);
            $created = false;
            if ($state === null) {
                $database->execute(
                    "INSERT INTO file_archive_jobs (job_id, user_id, account_id, operation, archive_path, destination, format, collision_policy, status) VALUES (?, ?, ?, 'extract', ?, ?, ?, ?, 'queued')",
                    [$jobId, $userId, $accountId, $request['archive'], $request['destination'], $request['format'], $request['collision']]
                );
                $created = true;
            } elseif ((int) $state['user_id'] !== $userId || (int) $state['account_id'] !== $accountId) {
                throw new AppException('Archive job ownership is invalid.', 403, 'archive_job_forbidden', [], 'security.idor');
            }
            return [$jobId, $created];
        });
        if ($created) {
            $this->audit->record($userId, $accountId, 'file.archive_queue', 'success', 'archive', $request['archive'], ['job_id' => $jobId, 'operation' => 'extract', 'destination' => $request['destination'], 'collision' => $request['collision']]);
        }
        return ['job_id' => $jobId, 'status' => 'queued', 'operation' => 'extract', 'archive' => $request['archive'], 'destination' => $request['destination'], 'collision' => $request['collision']];
    }

    /** @param array<string,mixed> $payload
     *  @return array{sources:list<string>,destination:string,format:string}
     */
    public function validateQueuedCreate(int $userId, int $accountId, array $payload): array
    {
        return $this->validateCreate($userId, $accountId, $payload);
    }

    /** @param array<string,mixed> $payload
     *  @return array{archive:string,destination:string,collision:string,format:string}
     */
    public function validateQueuedExtract(int $userId, int $accountId, array $payload): array
    {
        return $this->validateExtract($userId, $accountId, $payload);
    }

    /** @return array<string,mixed> */
    public function state(int $jobId, int $userId, int $accountId, string $operation): array
    {
        $state = $this->database->one('SELECT * FROM file_archive_jobs WHERE job_id = ? AND user_id = ? AND account_id = ? AND operation = ?', [$jobId, $userId, $accountId, $operation]);
        if ($state === null) {
            throw new AppException('Archive job state was not found or is not owned by this job.', 404, 'archive_job_not_found', [], 'security.idor');
        }
        return $state;
    }

    /** @param array<string,mixed>|null $validation
     *  @param array<string,mixed>|null $reconciliation
     *  @return array<string,mixed>
     */
    public function transition(int $jobId, int $userId, int $accountId, string $operation, string $status, ?array $validation = null, ?array $reconciliation = null, ?string $errorCode = null): array
    {
        if (!in_array($status, ['validating', 'downloading', 'validated', 'executing', 'verifying', 'reconciliation_required'], true)) {
            throw new AppException('Archive job transition is invalid.', 500, 'archive_job_transition_invalid');
        }
        $statement = $this->database->execute(
            'UPDATE file_archive_jobs SET status = ?, validation_json = COALESCE(?, validation_json), reconciliation_json = COALESCE(?, reconciliation_json), last_error_code = ?, started_at = COALESCE(started_at, CURRENT_TIMESTAMP) WHERE job_id = ? AND user_id = ? AND account_id = ? AND operation = ? AND status <> \'completed\'',
            [
                $status,
                $validation === null ? null : json_encode($validation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $reconciliation === null ? null : json_encode($reconciliation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $errorCode,
                $jobId,
                $userId,
                $accountId,
                $operation,
            ]
        );
        if ($statement->rowCount() !== 1) {
            $existing = $this->state($jobId, $userId, $accountId, $operation);
            if ((string) $existing['status'] !== 'completed') {
                throw new AppException('Archive job state could not be advanced.', 409, 'archive_job_state_conflict');
            }
            return $existing;
        }
        return $this->state($jobId, $userId, $accountId, $operation);
    }

    /** @param array<string,mixed> $details
     *  @return array<string,mixed>
     */
    public function complete(int $jobId, int $userId, int $accountId, string $operation, bool $reconciled, array $details = []): array
    {
        [$state, $changed] = $this->database->transaction(function (Database $database) use ($jobId, $userId, $accountId, $operation, $reconciled, $details): array {
            $state = $database->one('SELECT * FROM file_archive_jobs WHERE job_id = ? AND user_id = ? AND account_id = ? AND operation = ? FOR UPDATE', [$jobId, $userId, $accountId, $operation]);
            if ($state === null) {
                throw new AppException('Archive job completion state was not found.', 404, 'archive_job_not_found', [], 'security.idor');
            }
            if ((string) $state['status'] === 'completed') {
                return [$state, false];
            }
            $notificationParameters = json_encode(['name' => basename((string) $state['archive_path']), 'job' => $jobId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $notificationBody = $operation === 'create' ? 'notification.archive_create_done' : 'notification.archive_extract_done';
            if ($state['notification_id'] === null) {
                $database->execute(
                    'INSERT INTO notifications (user_id, type, title_key, body_key, parameters_json) VALUES (?, ?, ?, ?, ?)',
                    [$userId, 'file_archive', 'notification.archive_title', $notificationBody, $notificationParameters]
                );
                $database->execute('UPDATE file_archive_jobs SET notification_id = ? WHERE job_id = ?', [$database->lastInsertId(), $jobId]);
            } else {
                // A successful reconciliation replaces the earlier failure notice
                // instead of emitting a contradictory second notification.
                $database->execute(
                    'UPDATE notifications SET type = ?, title_key = ?, body_key = ?, parameters_json = ? WHERE id = ? AND user_id = ?',
                    ['file_archive', 'notification.archive_title', $notificationBody, $notificationParameters, $state['notification_id'], $userId]
                );
            }
            $database->execute(
                "UPDATE file_archive_jobs SET status = 'completed', reconciled = ?, reconciliation_json = COALESCE(?, reconciliation_json), last_error_code = NULL, completed_at = CURRENT_TIMESTAMP WHERE job_id = ? AND user_id = ? AND account_id = ? AND operation = ?",
                [$reconciled ? 1 : 0, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $jobId, $userId, $accountId, $operation]
            );
            return [$database->one('SELECT * FROM file_archive_jobs WHERE job_id = ?', [$jobId]) ?? $state, true];
        });
        if ($changed) {
            $this->audit->record($userId, $accountId, 'file.archive_' . $operation, 'success', 'archive', (string) $state['archive_path'], ['job_id' => $jobId, 'destination' => (string) $state['destination'], 'reconciled' => $reconciled] + $details);
        }
        return $state;
    }

    /** @param array<string,mixed> $details */
    public function fail(int $jobId, int $userId, int $accountId, string $operation, string $errorCode, bool $reconciliationRequired, array $details = []): void
    {
        $status = $reconciliationRequired ? 'reconciliation_required' : 'failed';
        $changed = $this->database->transaction(function (Database $database) use ($jobId, $userId, $accountId, $operation, $errorCode, $status, $details): bool {
            $state = $database->one('SELECT * FROM file_archive_jobs WHERE job_id = ? AND user_id = ? AND account_id = ? AND operation = ? FOR UPDATE', [$jobId, $userId, $accountId, $operation]);
            if ($state === null) {
                throw new AppException('Archive job failure state was not found.', 404, 'archive_job_not_found', [], 'security.idor');
            }
            if ((string) $state['status'] === 'completed') {
                return false;
            }
            if ((string) $state['status'] === $status && (string) ($state['last_error_code'] ?? '') === $errorCode) {
                return false;
            }
            if ($state['notification_id'] === null) {
                $database->execute(
                    'INSERT INTO notifications (user_id, type, title_key, body_key, parameters_json) VALUES (?, ?, ?, ?, ?)',
                    [$userId, 'file_archive', 'notification.archive_title', 'notification.archive_failed', json_encode(['name' => basename((string) $state['archive_path']), 'job' => $jobId, 'code' => $errorCode], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
                );
                $database->execute('UPDATE file_archive_jobs SET notification_id = ? WHERE job_id = ?', [$database->lastInsertId(), $jobId]);
            }
            $database->execute(
                'UPDATE file_archive_jobs SET status = ?, reconciliation_json = COALESCE(?, reconciliation_json), last_error_code = ?, completed_at = CURRENT_TIMESTAMP WHERE job_id = ? AND user_id = ? AND account_id = ? AND operation = ?',
                [$status, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $errorCode, $jobId, $userId, $accountId, $operation]
            );
            return true;
        });
        if (!$changed) {
            return;
        }
        $this->audit->record($userId, $accountId, 'file.archive_' . $operation, 'failed', 'archive', (string) ($this->state($jobId, $userId, $accountId, $operation)['archive_path'] ?? ''), ['job_id' => $jobId, 'error_code' => $errorCode, 'reconciliation_required' => $reconciliationRequired] + $details);
    }

    /** @param array<string,mixed> $payload
     *  @return array{sources:list<string>,destination:string,format:string}
     */
    private function validateCreate(int $userId, int $accountId, array $payload): array
    {
        $root = $this->root($userId, $accountId);
        $rawSources = $payload['sources'] ?? null;
        $format = is_string($payload['format'] ?? null) ? strtolower(trim((string) $payload['format'])) : '';
        if (!is_array($rawSources) || $rawSources === [] || count($rawSources) > 100 || !isset(self::FORMAT_SUFFIXES[$format])) {
            throw new AppException('Archive selection or format is invalid.', 422, 'invalid_archive_request', [], 'files.zip');
        }
        $sources = [];
        foreach ($rawSources as $source) {
            if (!is_string($source) || $source === '') {
                throw new AppException('Archive sources must contain valid paths.', 422, 'invalid_archive_request', [], 'files.zip');
            }
            $safe = $this->safeApi2Path($this->paths->normalize($source, $root));
            if (isset($sources[$safe])) {
                throw new AppException('Archive sources contain a duplicate path.', 422, 'archive_duplicate_source', [], 'files.zip');
            }
            $sources[$safe] = true;
        }
        $sourceList = array_keys($sources);
        if (in_array($format, ['gz', 'bz2'], true) && count($sourceList) !== 1) {
            throw new AppException('Gzip and Bzip2 creation accept exactly one source file.', 422, 'archive_single_source_required', [], 'files.zip');
        }
        $destination = $this->safeApi2Path($this->paths->normalize((string) ($payload['destination'] ?? ''), $root));
        if (!$this->hasSuffix($destination, self::FORMAT_SUFFIXES[$format])) {
            throw new AppException('Archive destination extension does not match its format.', 422, 'archive_extension_mismatch', ['format' => $format], 'files.zip');
        }
        foreach ($sourceList as $source) {
            if ($destination === $source || str_starts_with($destination . '/', rtrim($source, '/') . '/')) {
                throw new AppException('An archive cannot be written inside one of its selected sources.', 422, 'archive_destination_inside_source', [], 'files.zip');
            }
        }
        return ['sources' => $sourceList, 'destination' => $destination, 'format' => $format];
    }

    /** @param array<string,mixed> $payload
     *  @return array{archive:string,destination:string,collision:string,format:string}
     */
    private function validateExtract(int $userId, int $accountId, array $payload): array
    {
        $root = $this->root($userId, $accountId);
        $archive = $this->safeApi2Path($this->paths->normalize((string) ($payload['archive'] ?? ''), $root));
        $destination = $this->safeApi2Path($this->paths->normalize((string) ($payload['destination'] ?? ''), $root));
        $format = $this->format($archive);
        $collision = is_string($payload['collision'] ?? null) ? strtolower(trim((string) $payload['collision'])) : 'reject';
        if ($format === null) {
            throw new AppException('The selected file is not a supported archive.', 415, 'unsupported_archive', [], 'files.zip');
        }
        if (!in_array($collision, ['reject', 'overwrite'], true)) {
            throw new AppException('Archive extraction collision policy is invalid.', 422, 'invalid_collision_policy', [], 'files.zip');
        }
        if ($archive === $destination) {
            throw new AppException('The archive and extraction destination must be different paths.', 422, 'invalid_archive_destination', [], 'files.zip');
        }
        return ['archive' => $archive, 'destination' => $destination, 'collision' => $collision, 'format' => $format];
    }

    private function root(int $userId, int $accountId): string
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        return $root === '' ? '/' : $root;
    }

    private function safeApi2Path(string $path): string
    {
        if (strlen($path) > 1024 || str_contains($path, ',') || preg_match('/[\x00-\x1F\x7F]/u', $path)) {
            throw new AppException('Archive paths contain characters that cannot be represented safely by the cPanel archive API.', 422, 'invalid_archive_path', [], 'files.zip');
        }
        return $path;
    }

    /** @param list<string> $suffixes */
    private function hasSuffix(string $path, array $suffixes): bool
    {
        $lower = strtolower($path);
        foreach ($suffixes as $suffix) {
            if (str_ends_with($lower, $suffix) && strlen(basename($path)) > strlen($suffix)) {
                return true;
            }
        }
        return false;
    }

    private function format(string $path): ?string
    {
        foreach (self::FORMAT_SUFFIXES as $format => $suffixes) {
            if ($this->hasSuffix($path, $suffixes)) {
                return $format;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $request */
    private function fingerprint(string $operation, int $userId, int $accountId, array $request): string
    {
        $encoded = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return 'file.archive:' . $operation . ':' . hash('sha256', $userId . '|' . $accountId . '|' . $encoded) . ':' . intdiv(time(), 300);
    }
}
