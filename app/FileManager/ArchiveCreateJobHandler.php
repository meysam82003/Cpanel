<?php

declare(strict_types=1);

namespace App\FileManager;

use App\Core\AppException;
use App\Cpanel\CpanelApiException;
use App\Queue\JobContext;
use App\Queue\JobHandler;
use Throwable;

final class ArchiveCreateJobHandler implements JobHandler
{
    public function __construct(
        private readonly ArchiveService $archives,
        private readonly FileManagerService $files,
    ) {
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public function handle(JobContext $context, array $payload): array
    {
        $userId = $context->userId() ?? throw new AppException('Archive creation job has no owner.', 500, 'queue_owner_missing');
        $accountId = $context->accountId() ?? throw new AppException('Archive creation job has no host.', 500, 'queue_account_missing');
        $jobId = (int) $context->job['id'];
        $request = $this->archives->validateQueuedCreate($userId, $accountId, $payload);
        $state = $this->archives->state($jobId, $userId, $accountId, 'create');
        if ((string) $state['status'] === 'completed') {
            return $this->result($state, $request);
        }
        if (in_array((string) $state['status'], ['executing', 'verifying', 'reconciliation_required'], true)) {
            try {
                $output = $this->verifiedOutput($userId, $accountId, $request['destination']);
            } catch (Throwable $exception) {
                $this->archives->fail($jobId, $userId, $accountId, 'create', 'archive_reconciliation_required', true, ['provider_error' => $exception instanceof AppException ? $exception->safeCode : 'archive_output_verification_failed']);
                throw new AppException('The earlier archive output could not be reconciled safely.', 409, 'archive_reconciliation_required', [], 'files.zip');
            }
            if ($output !== null) {
                $context->progress(99, 'archive_reconciled');
                $completed = $this->archives->complete($jobId, $userId, $accountId, 'create', true, ['reason' => 'destination_found_after_interrupted_worker', 'output_bytes' => (int) $output['size']]);
                return $this->result($completed, $request);
            }
            $this->archives->fail($jobId, $userId, $accountId, 'create', 'archive_reconciliation_required', true);
            throw new AppException('The earlier archive attempt has an ambiguous provider outcome and cannot be repeated automatically.', 409, 'archive_reconciliation_required', [], 'files.zip');
        }
        if ((string) $state['status'] === 'failed') {
            throw new AppException('This archive job is final. Submit a new request after reviewing its error.', 409, 'archive_job_not_retryable', [], 'files.zip');
        }

        $externalStarted = false;
        try {
            $context->progress(5, 'archive_validating');
            $this->archives->transition($jobId, $userId, $accountId, 'create', 'validating');
            foreach ($request['sources'] as $source) {
                $info = $this->files->info($userId, $accountId, $source);
                $type = strtolower((string) ($info['type'] ?? ''));
                if (in_array($type, ['link', 'symlink'], true) || (bool) ($info['is_symlink'] ?? false)) {
                    throw new AppException('Symbolic links cannot be used as archive creation sources.', 403, 'archive_source_symlink_blocked', ['source' => $source], 'files.zip');
                }
                if (in_array($request['format'], ['gz', 'bz2'], true) && in_array($type, ['dir', 'directory'], true)) {
                    throw new AppException('Gzip and Bzip2 creation require a regular source file.', 422, 'archive_single_file_required', ['source' => $source], 'files.zip');
                }
            }
            if ($this->exists($userId, $accountId, $request['destination'])) {
                throw new AppException('An archive already exists at the selected destination.', 409, 'archive_destination_exists', ['destination' => $request['destination']], 'files.zip');
            }

            $this->archives->transition($jobId, $userId, $accountId, 'create', 'executing');
            $context->progress(35, 'archive_compressing');
            $externalStarted = true;
            $this->files->compress($userId, $accountId, $request['sources'], $request['destination'], $request['format']);
            $context->progress(88, 'archive_verifying');
            $this->archives->transition($jobId, $userId, $accountId, 'create', 'verifying');
            $output = $this->verifiedOutput($userId, $accountId, $request['destination']);
            if ($output === null) {
                throw new AppException('cPanel reported success but the archive output could not be verified.', 502, 'archive_output_missing', [], 'files.zip');
            }
            $context->progress(99, 'archive_completed');
            $completed = $this->archives->complete($jobId, $userId, $accountId, 'create', false, ['output_bytes' => (int) $output['size']]);
            return $this->result($completed, $request);
        } catch (Throwable $exception) {
            $safeCode = $exception instanceof AppException ? $exception->safeCode : 'archive_create_failed';
            if ($externalStarted) {
                try {
                    $output = $this->verifiedOutput($userId, $accountId, $request['destination']);
                    if ($output !== null) {
                        $context->progress(99, 'archive_reconciled');
                        $completed = $this->archives->complete($jobId, $userId, $accountId, 'create', true, ['reason' => 'destination_verified_after_provider_error', 'provider_error' => $safeCode, 'output_bytes' => (int) $output['size']]);
                        return $this->result($completed, $request);
                    }
                } catch (Throwable) {
                    $this->archives->fail($jobId, $userId, $accountId, 'create', 'archive_reconciliation_required', true, ['provider_error' => $safeCode]);
                    throw new AppException('Archive provider outcome could not be reconciled safely.', 409, 'archive_reconciliation_required', [], 'files.zip');
                }
                $ambiguous = $this->ambiguous($exception) || $safeCode === 'archive_output_missing';
                $this->archives->fail($jobId, $userId, $accountId, 'create', $ambiguous ? 'archive_reconciliation_required' : $safeCode, $ambiguous, ['provider_error' => $safeCode]);
                if ($ambiguous) {
                    throw new AppException('Archive provider outcome is ambiguous; automatic replay was blocked.', 409, 'archive_reconciliation_required', [], 'files.zip');
                }
            } else {
                $this->archives->fail($jobId, $userId, $accountId, 'create', $safeCode, false);
            }
            throw $exception;
        }
    }

    private function exists(int $userId, int $accountId, string $path): bool
    {
        try {
            $this->files->info($userId, $accountId, $path);
            return true;
        } catch (AppException $exception) {
            if ($exception->safeCode === 'remote_path_not_found') {
                return false;
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    private function verifiedOutput(int $userId, int $accountId, string $path): ?array
    {
        try {
            $info = $this->files->info($userId, $accountId, $path);
        } catch (AppException $exception) {
            if ($exception->safeCode === 'remote_path_not_found') {
                return null;
            }
            throw $exception;
        }
        $type = strtolower((string) ($info['type'] ?? 'file'));
        if (in_array($type, ['dir', 'directory', 'link', 'symlink'], true) || (bool) ($info['is_symlink'] ?? false) || (int) ($info['size'] ?? 0) < 1) {
            throw new AppException('The archive output is not a non-empty regular file.', 502, 'archive_output_invalid', [], 'files.zip');
        }
        return $info;
    }

    private function ambiguous(Throwable $exception): bool
    {
        if (!$exception instanceof AppException) {
            return true;
        }
        return $exception instanceof CpanelApiException && in_array($exception->safeCode, ['cpanel_timeout', 'cpanel_network_error', 'cpanel_http_error', 'cpanel_invalid_json', 'cpanel_invalid_response', 'cpanel_response_too_large'], true);
    }

    /** @param array<string,mixed> $state
     *  @param array{sources:list<string>,destination:string,format:string} $request
     *  @return array<string,mixed>
     */
    private function result(array $state, array $request): array
    {
        $details = json_decode((string) ($state['reconciliation_json'] ?? ''), true);
        $details = is_array($details) ? $details : [];
        return [
            'job_id' => (int) $state['job_id'],
            'operation' => 'create',
            'destination' => $request['destination'],
            'format' => $request['format'],
            'source_count' => count($request['sources']),
            'bytes' => isset($details['output_bytes']) ? (int) $details['output_bytes'] : null,
            'reconciled' => (bool) $state['reconciled'],
            'completed' => (string) $state['status'] === 'completed',
        ];
    }
}
