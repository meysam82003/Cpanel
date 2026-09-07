<?php

declare(strict_types=1);

namespace App\FileManager;

use App\Core\AppException;
use App\Core\Database;
use App\Core\Translator;
use App\Queue\JobContext;
use App\Queue\JobHandler;
use App\Telegram\TelegramClient;

final class FileDownloadJobHandler implements JobHandler
{
    public function __construct(
        private readonly DownloadService $downloads,
        private readonly Database $database,
        private readonly TelegramClient $telegram,
        private readonly Translator $translator,
        private readonly string $appUrl,
        private readonly int $telegramMaxBytes = 50_000_000,
    ) {
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public function handle(JobContext $context, array $payload): array
    {
        $userId = $context->userId();
        $accountId = $context->accountId();
        $downloadId = filter_var($payload['download_token_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === null || $accountId === null || $downloadId === false) {
            throw new AppException('The queued download payload is invalid.', 422, 'download_preparation_invalid');
        }
        $context->progress(5, 'download_validating');
        $file = $this->downloads->prepare($userId, $accountId, (int) $downloadId, (int) $context->job['id'], $context->leaseToken());
        $delivery = is_array($payload['telegram_delivery'] ?? null) ? $payload['telegram_delivery'] : null;
        $delivered = null;
        if ($delivery !== null) {
            $context->progress(90, 'download_delivering');
            $delivered = $this->deliverToTelegram($userId, $accountId, (int) $downloadId, $file, $delivery);
        }
        $context->progress(99, 'download_ready');
        return [
            'download_token_id' => (int) $downloadId,
            'filename' => $file['filename'],
            'bytes' => $file['bytes'],
            'sha256' => $file['sha256'],
            'ready' => true,
            'delivery' => $delivered,
        ];
    }

    /** @param array{path:string,filename:string,content_type:string,bytes:int,sha256:string} $file
     *  @param array<string,mixed> $delivery
     */
    private function deliverToTelegram(int $userId, int $accountId, int $downloadId, array $file, array $delivery): string
    {
        $chatId = filter_var($delivery['chat_id'] ?? null, FILTER_VALIDATE_INT);
        $token = (string) ($delivery['download_token'] ?? '');
        $language = ($delivery['language'] ?? null) === 'fa' ? 'fa' : 'en';
        if ($chatId === false || $chatId === 0 || !preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
            throw new AppException('Telegram delivery state is invalid.', 422, 'telegram_delivery_invalid');
        }
        $owner = $this->database->one("SELECT id FROM users WHERE id = ? AND telegram_id = ? AND status = 'active'", [$userId, (int) $chatId]);
        if ($owner === null) {
            throw new AppException('Telegram delivery is not bound to the account owner private chat.', 403, 'telegram_delivery_forbidden', [], 'security.idor');
        }

        if ($file['bytes'] <= max(1_048_576, min(50_000_000, $this->telegramMaxBytes))) {
            $this->telegram->call('sendChatAction', ['chat_id' => (int) $chatId, 'action' => 'upload_document']);
            $this->telegram->call('sendDocument', [
                'chat_id' => (int) $chatId,
                'document' => new \CURLFile($file['path'], $file['content_type'], $file['filename']),
                'caption' => $this->translator->get('files.download_complete', $language),
            ]);
            $this->downloads->completeTelegramDelivery($userId, $accountId, $downloadId);
            return 'telegram_document';
        }

        $url = rtrim($this->appUrl, '/') . '/download/' . rawurlencode($token);
        $this->telegram->call('sendMessage', [
            'chat_id' => (int) $chatId,
            'text' => $this->translator->get('files.download_large', $language) . "\n" . $url,
            'disable_web_page_preview' => true,
        ]);
        return 'secure_link';
    }
}
