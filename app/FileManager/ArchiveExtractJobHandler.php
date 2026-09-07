<?php

declare(strict_types=1);

namespace App\FileManager;

use App\Accounts\AccountRepository;
use App\Core\AppException;
use App\Cpanel\CpanelApiException;
use App\Cpanel\UapiClient;
use App\Queue\JobContext;
use App\Queue\JobHandler;
use Throwable;

final class ArchiveExtractJobHandler implements JobHandler
{
    private readonly int $maxCompressedBytes;
    private readonly int $maxExpandedBytes;
    private readonly int $maxFiles;
    private readonly int $maxRatio;
    private readonly int $maxTopLevel;

    public function __construct(
        private readonly ArchiveService $archives,
        private readonly FileManagerService $files,
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly ArchiveSafetyValidator $validator,
        private readonly string $tempRoot,
        int $maxCompressedBytes = 268_435_456,
        int $maxExpandedBytes = 1_073_741_824,
        int $maxFiles = 10_000,
        int $maxRatio = 200,
        int $maxTopLevel = 500,
    ) {
        $this->maxCompressedBytes = max(1_048_576, min(1_073_741_824, $maxCompressedBytes));
        $this->maxExpandedBytes = max(1_048_576, min(10_737_418_240, $maxExpandedBytes));
        $this->maxFiles = max(1, min(100_000, $maxFiles));
        $this->maxRatio = max(2, min(1_000, $maxRatio));
        $this->maxTopLevel = max(1, min(5_000, $maxTopLevel));
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public function handle(JobContext $context, array $payload): array
    {
        $userId = $context->userId() ?? throw new AppException('Archive extraction job has no owner.', 500, 'queue_owner_missing');
        $accountId = $context->accountId() ?? throw new AppException('Archive extraction job has no host.', 500, 'queue_account_missing');
        $jobId = (int) $context->job['id'];
        $request = $this->archives->validateQueuedExtract($userId, $accountId, $payload);
        $state = $this->archives->state($jobId, $userId, $accountId, 'extract');
        if ((string) $state['status'] === 'completed') {
            return $this->result($state, $request, $this->decoded((string) ($state['validation_json'] ?? '')));
        }
        if (in_array((string) $state['status'], ['executing', 'verifying', 'reconciliation_required'], true)) {
            return $this->reconcileInterrupted($context, $state, $request);
        }
        if ((string) $state['status'] === 'validated') {
            $reconciliation = $this->decoded((string) ($state['reconciliation_json'] ?? ''));
            $priorStaging = $reconciliation['staging_archive'] ?? null;
            if (is_string($priorStaging) && !$this->deleteStaging($userId, $accountId, $priorStaging)) {
                $this->archives->fail($jobId, $userId, $accountId, 'extract', 'archive_staging_cleanup_failed', false);
                throw new AppException('A stale validated archive copy could not be removed safely.', 502, 'archive_staging_cleanup_failed', [], 'files.zip');
            }
        }
        if ((string) $state['status'] === 'failed') {
            throw new AppException('This extraction job is final. Submit a new request after reviewing its error.', 409, 'archive_job_not_retryable', [], 'files.zip');
        }

        $temporary = null;
        $verificationTemporary = null;
        $stagingArchive = null;
        $preserveStaging = false;
        $externalStarted = false;
        $summary = [];
        $preexisting = [];
        try {
            $context->progress(5, 'archive_validating');
            $this->archives->transition($jobId, $userId, $accountId, 'extract', 'validating');
            $archiveInfo = $this->files->info($userId, $accountId, $request['archive']);
            $archiveType = strtolower((string) ($archiveInfo['type'] ?? ''));
            if (in_array($archiveType, ['dir', 'directory', 'link', 'symlink'], true)) {
                throw new AppException('The extraction source must be a regular archive file.', 422, 'invalid_archive_source', [], 'files.zip');
            }
            $reportedSize = max(0, (int) ($archiveInfo['size'] ?? 0));
            if ($reportedSize > $this->maxCompressedBytes) {
                throw new AppException('The archive exceeds the configured inspection download limit.', 413, 'archive_inspection_limit', ['limit' => $this->maxCompressedBytes], 'files.zip');
            }
            $destinationInfo = $this->files->info($userId, $accountId, $request['destination']);
            if (!in_array(strtolower((string) ($destinationInfo['type'] ?? '')), ['dir', 'directory'], true)) {
                throw new AppException('Archive extraction destination must be an existing directory.', 422, 'invalid_archive_destination', [], 'files.zip');
            }

            $temporary = $this->temporaryPath($jobId, $request['format']);
            $this->archives->transition($jobId, $userId, $accountId, 'extract', 'downloading');
            $context->progress(15, 'archive_downloading');
            $download = $this->cpanel->downloadTo($this->accounts->connection($userId, $accountId), $request['archive'], $temporary, $this->maxCompressedBytes);
            clearstatcache(true, $temporary);
            $actualSize = is_file($temporary) ? filesize($temporary) : false;
            if ($actualSize === false || (int) $actualSize !== (int) $download['bytes']) {
                throw new AppException('Downloaded archive size does not match the streamed byte count.', 422, 'archive_integrity_failed', [], 'files.zip');
            }

            $context->progress(45, 'archive_inspecting');
            $summary = $this->validator->validate($temporary, basename($request['archive']), $this->maxFiles, $this->maxExpandedBytes, $this->maxRatio);
            if (!hash_equals((string) $download['sha256'], (string) $summary['sha256']) || (int) $summary['compressed_bytes'] !== (int) $download['bytes']) {
                throw new AppException('Archive integrity changed between download and validation.', 422, 'archive_integrity_failed', [], 'files.zip');
            }
            if (count($summary['top_level']) > $this->maxTopLevel) {
                throw new AppException('Archive has too many top-level outputs for safe collision verification.', 413, 'archive_top_level_limit', ['limit' => $this->maxTopLevel], 'files.zip');
            }
            $preexisting = $this->existingOutputs($userId, $accountId, $request['destination'], $summary['top_level']);
            if ($request['collision'] === 'reject' && $preexisting !== []) {
                throw new AppException('Extraction would overwrite existing destination entries.', 409, 'archive_collision', ['entries' => array_slice($preexisting, 0, 20)], 'files.zip');
            }
            $stagingArchive = rtrim(dirname($request['archive']), '/') . '/.tcm-validated-archive-' . $jobId . '-' . bin2hex(random_bytes(16)) . $this->suffix($request['format']);
            $this->archives->transition($jobId, $userId, $accountId, 'extract', 'validated', $summary, ['preexisting' => $preexisting, 'staging_archive' => $stagingArchive]);
            $context->progress(55, 'archive_staging_validated_copy');
            $this->files->upload($userId, $accountId, dirname($stagingArchive), [['path' => $temporary, 'name' => basename($stagingArchive)]], 'reject', ['source' => 'validated_archive_staging', 'job_id' => $jobId, 'sha256' => $summary['sha256']]);
            $verificationTemporary = $this->temporaryPath($jobId, 'verify.' . $request['format']);
            $stagedDownload = $this->cpanel->downloadTo($this->accounts->connection($userId, $accountId), $stagingArchive, $verificationTemporary, $this->maxCompressedBytes);
            if ((int) $stagedDownload['bytes'] !== (int) $summary['compressed_bytes'] || !hash_equals((string) $summary['sha256'], (string) $stagedDownload['sha256'])) {
                throw new AppException('The remote staged archive does not match the validated local copy.', 422, 'archive_staging_integrity_failed', [], 'files.zip');
            }
            @unlink($verificationTemporary);
            $verificationTemporary = null;

            $context->progress(65, 'archive_extracting');
            $this->archives->transition($jobId, $userId, $accountId, 'extract', 'executing');
            $externalStarted = true;
            $this->files->extract($userId, $accountId, $stagingArchive, $request['destination']);
            $this->archives->transition($jobId, $userId, $accountId, 'extract', 'verifying');
            $context->progress(90, 'archive_verifying');
            $presence = $this->outputPresence($userId, $accountId, $request['destination'], $summary['top_level']);
            if ($presence['missing'] !== []) {
                throw new AppException('cPanel reported success but one or more archive outputs are missing.', 502, 'archive_output_missing', ['missing' => array_slice($presence['missing'], 0, 20)], 'files.zip');
            }
            $context->progress(99, 'archive_completed');
            $completed = $this->archives->complete($jobId, $userId, $accountId, 'extract', false, ['verified_outputs' => count($presence['present']), 'overwritten_outputs' => count($preexisting)]);
            return $this->result($completed, $request, $summary);
        } catch (Throwable $exception) {
            $safeCode = $exception instanceof AppException ? $exception->safeCode : 'archive_extract_failed';
            if ($externalStarted) {
                try {
                    $presence = $this->outputPresence($userId, $accountId, $request['destination'], is_array($summary['top_level'] ?? null) ? $summary['top_level'] : []);
                    $newOutputs = array_values(array_diff($presence['present'], $preexisting));
                    if ($presence['missing'] === [] && $newOutputs !== []) {
                        $context->progress(99, 'archive_reconciled');
                        $completed = $this->archives->complete($jobId, $userId, $accountId, 'extract', true, ['reason' => 'outputs_verified_after_provider_error', 'provider_error' => $safeCode, 'verified_outputs' => count($presence['present'])]);
                        return $this->result($completed, $request, $summary);
                    }
                } catch (Throwable) {
                    $preserveStaging = true;
                    $this->archives->fail($jobId, $userId, $accountId, 'extract', 'archive_reconciliation_required', true, ['provider_error' => $safeCode]);
                    throw new AppException('Extraction provider outcome could not be reconciled safely.', 409, 'archive_reconciliation_required', [], 'files.zip');
                }
                $ambiguous = $this->ambiguous($exception) || $safeCode === 'archive_output_missing' || $presence['present'] !== [];
                $preserveStaging = $ambiguous;
                $this->archives->fail($jobId, $userId, $accountId, 'extract', $ambiguous ? 'archive_reconciliation_required' : $safeCode, $ambiguous, ['provider_error' => $safeCode, 'present_outputs' => count($presence['present']), 'missing_outputs' => count($presence['missing'])]);
                if ($ambiguous) {
                    throw new AppException('Extraction may have changed destination files; automatic replay was blocked.', 409, 'archive_reconciliation_required', [], 'files.zip');
                }
            } else {
                $this->archives->fail($jobId, $userId, $accountId, 'extract', $safeCode, false);
            }
            throw $exception;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            if (is_string($verificationTemporary) && is_file($verificationTemporary)) {
                @unlink($verificationTemporary);
            }
            if (is_string($stagingArchive) && !$preserveStaging) {
                $this->deleteStaging($userId, $accountId, $stagingArchive);
            }
        }
    }

    /** @param array<string,mixed> $state
     *  @param array{archive:string,destination:string,collision:string,format:string} $request
     *  @return array<string,mixed>
     */
    private function reconcileInterrupted(JobContext $context, array $state, array $request): array
    {
        $userId = (int) $state['user_id'];
        $accountId = (int) $state['account_id'];
        $jobId = (int) $state['job_id'];
        $summary = $this->decoded((string) ($state['validation_json'] ?? ''));
        $reconciliation = $this->decoded((string) ($state['reconciliation_json'] ?? ''));
        $topLevel = is_array($summary['top_level'] ?? null) ? array_values(array_map('strval', $summary['top_level'])) : [];
        $preexisting = is_array($reconciliation['preexisting'] ?? null) ? array_values(array_map('strval', $reconciliation['preexisting'])) : [];
        if ($topLevel !== []) {
            try {
                $presence = $this->outputPresence($userId, $accountId, $request['destination'], $topLevel);
                $newOutputs = array_values(array_diff($presence['present'], $preexisting));
                if ($presence['missing'] === [] && $newOutputs !== []) {
                    $context->progress(99, 'archive_reconciled');
                    $completed = $this->archives->complete($jobId, $userId, $accountId, 'extract', true, ['reason' => 'outputs_found_after_interrupted_worker', 'verified_outputs' => count($presence['present'])]);
                    if (is_string($reconciliation['staging_archive'] ?? null)) {
                        $this->deleteStaging($userId, $accountId, (string) $reconciliation['staging_archive']);
                    }
                    return $this->result($completed, $request, $summary);
                }
                $this->archives->fail($jobId, $userId, $accountId, 'extract', 'archive_reconciliation_required', true, ['present_outputs' => count($presence['present']), 'missing_outputs' => count($presence['missing'])]);
            } catch (Throwable) {
                $this->archives->fail($jobId, $userId, $accountId, 'extract', 'archive_reconciliation_required', true);
            }
        } else {
            $this->archives->fail($jobId, $userId, $accountId, 'extract', 'archive_reconciliation_required', true);
        }
        throw new AppException('The earlier extraction has an ambiguous provider outcome and cannot be repeated automatically.', 409, 'archive_reconciliation_required', [], 'files.zip');
    }

    /** @param list<string> $topLevel
     *  @return list<string>
     */
    private function existingOutputs(int $userId, int $accountId, string $destination, array $topLevel): array
    {
        return $this->outputPresence($userId, $accountId, $destination, $topLevel)['present'];
    }

    /** @param list<string> $topLevel
     *  @return array{present:list<string>,missing:list<string>}
     */
    private function outputPresence(int $userId, int $accountId, string $destination, array $topLevel): array
    {
        $present = [];
        $missing = [];
        foreach ($topLevel as $entry) {
            $path = rtrim($destination, '/') . '/' . ltrim($entry, '/');
            try {
                $this->files->info($userId, $accountId, $path);
                $present[] = $entry;
            } catch (AppException $exception) {
                if ($exception->safeCode !== 'remote_path_not_found') {
                    throw $exception;
                }
                $missing[] = $entry;
            }
        }
        return ['present' => $present, 'missing' => $missing];
    }

    private function temporaryPath(int $jobId, string $format): string
    {
        $directory = rtrim($this->tempRoot, '/');
        if (is_link($directory)) {
            throw new AppException('Secure archive inspection storage must not be a symbolic link.', 500, 'archive_storage_unavailable');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new AppException('Secure archive inspection storage is unavailable.', 500, 'archive_storage_unavailable');
        }
        @chmod($directory, 0700);
        $real = realpath($directory);
        if ($real === false || !is_writable($real)) {
            throw new AppException('Secure archive inspection storage is unavailable.', 500, 'archive_storage_unavailable');
        }
        $suffix = preg_replace('/[^a-z0-9.]/', '', strtolower($format)) ?: 'archive';
        return $real . '/archive-inspect-' . $jobId . '-' . bin2hex(random_bytes(16)) . '.' . $suffix;
    }

    private function suffix(string $format): string
    {
        return match ($format) {
            'zip' => '.zip',
            'tar.gz' => '.tar.gz',
            'tar.bz2' => '.tar.bz2',
            'tar' => '.tar',
            'gz' => '.gz',
            'bz2' => '.bz2',
            default => throw new AppException('Archive staging format is invalid.', 422, 'unsupported_archive', [], 'files.zip'),
        };
    }

    private function deleteStaging(int $userId, int $accountId, string $path): bool
    {
        try {
            $this->files->delete($userId, $accountId, $path, true);
            return true;
        } catch (AppException $exception) {
            return $exception->safeCode === 'remote_path_not_found';
        } catch (Throwable) {
            return false;
        }
    }

    private function ambiguous(Throwable $exception): bool
    {
        if (!$exception instanceof AppException) {
            return true;
        }
        return $exception instanceof CpanelApiException && in_array($exception->safeCode, ['cpanel_timeout', 'cpanel_network_error', 'cpanel_http_error', 'cpanel_invalid_json', 'cpanel_invalid_response', 'cpanel_response_too_large'], true);
    }

    /** @return array<string,mixed> */
    private function decoded(string $json): array
    {
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $state
     *  @param array{archive:string,destination:string,collision:string,format:string} $request
     *  @param array<string,mixed> $summary
     *  @return array<string,mixed>
     */
    private function result(array $state, array $request, array $summary): array
    {
        return [
            'job_id' => (int) $state['job_id'],
            'operation' => 'extract',
            'archive' => $request['archive'],
            'destination' => $request['destination'],
            'collision' => $request['collision'],
            'format' => $summary['format'] ?? $request['format'],
            'files' => isset($summary['files']) ? (int) $summary['files'] : null,
            'uncompressed_bytes' => isset($summary['uncompressed_bytes']) ? (int) $summary['uncompressed_bytes'] : null,
            'sha256' => $summary['sha256'] ?? null,
            'reconciled' => (bool) $state['reconciled'],
            'completed' => (string) $state['status'] === 'completed',
        ];
    }
}
