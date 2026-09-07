<?php

declare(strict_types=1);

namespace App\FileManager;

use App\Accounts\AccountRepository;
use App\Core\AppException;
use App\Core\Database;
use App\Plans\PlanGuard;
use App\Queue\QueueService;
use App\Security\PathGuard;

final class TelegramUploadService
{
    private const OFFICIAL_BOT_API_DOWNLOAD_LIMIT = 20_000_000;

    private readonly int $telegramMaxBytes;

    public function __construct(
        private readonly Database $database,
        private readonly AccountRepository $accounts,
        private readonly PlanGuard $plans,
        private readonly PathGuard $paths,
        private readonly QueueService $queue,
        int $telegramMaxBytes = self::OFFICIAL_BOT_API_DOWNLOAD_LIMIT,
    ) {
        $this->telegramMaxBytes = max(1, min(self::OFFICIAL_BOT_API_DOWNLOAD_LIMIT, $telegramMaxBytes));
    }

    /** @param array<string,mixed> $document
     *  @return array{job_id:int,filename:string,max_bytes:int,status:string}
     */
    public function enqueue(
        int $userId,
        int $accountId,
        int $chatId,
        int $messageId,
        array $document,
        string $directory,
        string $collision,
        string $language,
    ): array {
        if ($messageId < 1) {
            throw new AppException('Telegram upload message identifier is invalid.', 422, 'telegram_upload_invalid', [], 'files.upload');
        }
        $validated = $this->validate($userId, $accountId, $chatId, [
            'file_id' => $document['file_id'] ?? null,
            'file_unique_id' => $document['file_unique_id'] ?? null,
            'filename' => $document['file_name'] ?? 'telegram-document.bin',
            'reported_size' => $document['file_size'] ?? null,
            'directory' => $directory,
            'collision' => $collision,
            'chat_id' => $chatId,
            'language' => $language,
        ]);
        $this->plans->dailyOperation($userId);

        $identity = $validated['file_unique_id'] ?? hash('sha256', $validated['file_id']);
        $idempotency = 'telegram.file-upload:' . $chatId . ':' . $messageId . ':' . $identity;
        $jobId = $this->database->transaction(function (Database $database) use ($userId, $accountId, $chatId, $validated, $idempotency): int {
            $jobId = $this->queue->dispatch('telegram.file_upload', $userId, $accountId, $validated, $idempotency, 'default', 3);
            $state = $database->one('SELECT job_id, user_id, account_id FROM telegram_file_uploads WHERE job_id = ? FOR UPDATE', [$jobId]);
            if ($state === null) {
                $database->execute(
                    "INSERT INTO telegram_file_uploads (job_id, user_id, account_id, telegram_chat_id, remote_directory, requested_filename, collision_policy, status, reported_size) VALUES (?, ?, ?, ?, ?, ?, ?, 'queued', ?)",
                    [$jobId, $userId, $accountId, $chatId, $validated['directory'], $validated['filename'], $validated['collision'], $validated['reported_size']]
                );
            } elseif ((int) $state['user_id'] !== $userId || (int) $state['account_id'] !== $accountId) {
                throw new AppException('Telegram upload job ownership is invalid.', 403, 'telegram_upload_forbidden', [], 'security.idor');
            }
            return $jobId;
        });

        return ['job_id' => $jobId, 'filename' => $validated['filename'], 'max_bytes' => $validated['max_bytes'], 'status' => 'queued'];
    }

    /** @param array<string,mixed> $payload
     *  @return array{file_id:string,file_unique_id:?string,filename:string,reported_size:?int,directory:string,collision:string,chat_id:int,language:string,max_bytes:int}
     */
    public function validateQueued(int $userId, int $accountId, array $payload): array
    {
        $chatId = filter_var($payload['chat_id'] ?? null, FILTER_VALIDATE_INT);
        if ($chatId === false) {
            throw new AppException('Queued Telegram chat identifier is invalid.', 422, 'telegram_upload_invalid');
        }
        return $this->validate($userId, $accountId, (int) $chatId, $payload);
    }

    /** @param array<string,mixed> $payload
     *  @return array{file_id:string,file_unique_id:?string,filename:string,reported_size:?int,directory:string,collision:string,chat_id:int,language:string,max_bytes:int}
     */
    private function validate(int $userId, int $accountId, int $chatId, array $payload): array
    {
        if ($userId < 1 || $accountId < 1 || $chatId < 1) {
            throw new AppException('Telegram uploads are accepted only in the account owner private chat.', 403, 'telegram_upload_forbidden', [], 'security.idor');
        }
        $owner = $this->database->one("SELECT id FROM users WHERE id = ? AND telegram_id = ? AND status = 'active'", [$userId, $chatId]);
        if ($owner === null) {
            throw new AppException('Telegram upload is not bound to the account owner private chat.', 403, 'telegram_upload_forbidden', [], 'security.idor');
        }
        $account = $this->accounts->getOwned($userId, $accountId);
        $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $directory = $this->paths->normalize((string) ($payload['directory'] ?? ''), $root);
        $rawName = (string) ($payload['filename'] ?? 'telegram-document.bin');
        if (str_contains($rawName, '/') || str_contains($rawName, '\\')) {
            throw new AppException('Telegram document filename must not contain path segments.', 422, 'invalid_filename', [], 'security.path');
        }
        $filename = $this->paths->sanitizeFilename($rawName);
        $fileId = $this->opaqueFileId($payload['file_id'] ?? null, false);
        $uniqueId = $this->opaqueFileId($payload['file_unique_id'] ?? null, true);
        $collision = (string) ($payload['collision'] ?? '');
        if (!in_array($collision, ['overwrite', 'rename'], true)) {
            throw new AppException('Telegram upload collision policy is invalid.', 422, 'invalid_collision_policy', [], 'files.upload');
        }
        $reportedSize = $this->optionalSize($payload['reported_size'] ?? null);
        $plan = $this->plans->plan($userId);
        $maxBytes = min(max(1, (int) $plan['max_upload_bytes']), $this->telegramMaxBytes);
        if ($reportedSize !== null) {
            $this->plans->upload($userId, $reportedSize);
            if ($reportedSize > $maxBytes) {
                throw new AppException('The Telegram document exceeds the available Bot API or plan limit.', 413, 'upload_size_exceeded', ['limit' => $maxBytes], 'files.upload');
            }
        }

        return [
            'file_id' => $fileId,
            'file_unique_id' => $uniqueId,
            'filename' => $filename,
            'reported_size' => $reportedSize,
            'directory' => $directory,
            'collision' => $collision,
            'chat_id' => $chatId,
            'language' => ($payload['language'] ?? null) === 'fa' ? 'fa' : 'en',
            'max_bytes' => $maxBytes,
        ];
    }

    private function opaqueFileId(mixed $value, bool $optional): ?string
    {
        if ($optional && ($value === null || $value === '')) {
            return null;
        }
        if (!is_string($value) || strlen($value) < 1 || strlen($value) > 512 || preg_match('/^[\x21-\x7e]+$/D', $value) !== 1) {
            throw new AppException('Telegram file identifier is invalid.', 422, 'telegram_upload_invalid', [], 'files.upload');
        }
        return $value;
    }

    private function optionalSize(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && (!is_string($value) || preg_match('/^\d+$/D', $value) !== 1)) || (int) $value < 0) {
            throw new AppException('Telegram file size is invalid.', 422, 'telegram_upload_invalid', [], 'files.upload');
        }
        return (int) $value;
    }
}
