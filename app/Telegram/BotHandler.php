<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Accounts\AccountRepository;
use App\Accounts\AccountService;
use App\Accounts\UserRepository;
use App\Accounts\UserSettingsService;
use App\Admin\AdminService;
use App\Core\AppException;
use App\Core\Database;
use App\Core\Translator;
use App\Help\HelpService;
use App\FileManager\DownloadService;
use App\FileManager\FileManagerService;
use App\FileManager\TelegramUploadService;
use App\Security\ConfirmationService;
use App\Security\RateLimiter;
use App\Security\SecurityCenterService;

final class BotHandler
{
    public function __construct(
        private readonly Database $database,
        private readonly TelegramClient $telegram,
        private readonly UserRepository $users,
        private readonly AccountRepository $accounts,
        private readonly AccountService $accountService,
        private readonly UserSettingsService $settings,
        private readonly SecurityCenterService $security,
        private readonly AdminService $admin,
        private readonly HelpService $help,
        private readonly Translator $translator,
        private readonly BotSessionService $sessions,
        private readonly CallbackStateService $callbacks,
        private readonly ConfirmationService $confirmations,
        private readonly RateLimiter $limits,
        private readonly string $miniAppUrl,
        private readonly FileManagerService $files,
        private readonly DownloadService $downloads,
        private readonly TelegramUploadService $uploads,
    ) {
    }

    /** @param array<string,mixed> $update */
    public function handle(array $update): void
    {
        try {
            if (isset($update['callback_query']) && is_array($update['callback_query'])) {
                $this->callback($update['callback_query']);
                return;
            }
            if (isset($update['message']) && is_array($update['message'])) {
                $this->message($update['message']);
            }
        } catch (AppException $exception) {
            if ($exception instanceof TelegramApiException) {
                throw $exception;
            }
            $this->sendOperationError($update, $exception);
        }
    }

    /** @param array<string,mixed> $message */
    private function message(array $message): void
    {
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $chatId = filter_var($message['chat']['id'] ?? null, FILTER_VALIDATE_INT);
        if ($chatId === false || !isset($from['id'])) {
            return;
        }
        $user = $this->users->upsertTelegram($from);
        $userId = (int) $user['id'];
        $language = $this->language($user);
        $this->limits->hit('telegram.global', 'all', 1200, 60);
        $this->limits->hit('telegram.command', $userId, 45, 60);
        $text = trim((string) ($message['text'] ?? ''));
        $isCancel = preg_match('/^\/cancel(?:@[A-Za-z0-9_]+)?\s*$/i', $text) === 1;
        $accessBlock = $this->accessBlock($user);
        if ($accessBlock !== null && !$isCancel) {
            $this->send($chatId, htmlspecialchars($accessBlock, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            return;
        }
        if (preg_match('/^\/(start|panel|hosts|help|security|settings|cancel|admin)(?:@[A-Za-z0-9_]+)?(?:\s+([A-Za-z0-9_-]{1,64}))?\s*$/i', $text, $match)) {
            $this->command(strtolower($match[1]), $user, (int) $chatId, $match[2] ?? null);
            return;
        }
        $session = $this->sessions->get($userId);
        if (isset($message['document']) && is_array($message['document'])) {
            if ($session !== null && $session['state'] === 'waiting_for_upload_document') {
                $this->documentInput($user, (int) $chatId, (int) ($message['message_id'] ?? 0), $message['document'], $session);
            } else {
                $this->send((int) $chatId, $this->translator->get('files.upload_start_first', $language));
            }
            return;
        }
        if ($session !== null && $session['state'] !== null) {
            $this->stateInput($user, (int) $chatId, (int) ($message['message_id'] ?? 0), $text, $session);
            return;
        }
        $this->mainMenu($user, (int) $chatId);
    }

    /** @param array<string,mixed> $query */
    private function callback(array $query): void
    {
        $from = is_array($query['from'] ?? null) ? $query['from'] : [];
        $message = is_array($query['message'] ?? null) ? $query['message'] : [];
        $chatId = filter_var($message['chat']['id'] ?? null, FILTER_VALIDATE_INT);
        $callbackId = (string) ($query['id'] ?? '');
        if ($chatId === false || !isset($from['id']) || $callbackId === '') {
            return;
        }
        $user = $this->users->upsertTelegram($from);
        $userId = (int) $user['id'];
        $language = $this->language($user);
        try {
            $this->limits->hit('telegram.callback', $userId, 60, 60);
            $state = $this->callbacks->consume($userId, (string) ($query['data'] ?? ''));
            $accessBlock = $this->accessBlock($user);
            if ($accessBlock !== null && $state['action'] !== 'session.cancel') {
                $this->telegram->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => mb_substr($accessBlock, 0, 190), 'show_alert' => true]);
                return;
            }
            $this->telegram->call('answerCallbackQuery', ['callback_query_id' => $callbackId]);
            $action = $state['action'];
            $payload = $state['payload'];
            switch ($action) {
                case 'language.select':
                    $selected = ($payload['language'] ?? null) === 'en' ? 'en' : 'fa';
                    $this->settings->language($userId, $selected);
                    $this->database->execute('UPDATE users SET onboarding_step = 1 WHERE id = ?', [$userId]);
                    $user['language'] = $selected;
                    $this->onboarding($user, (int) $chatId, 1);
                    break;
                case 'onboarding.goto':
                    $step = max(1, min(7, (int) ($payload['step'] ?? 1)));
                    $this->database->execute('UPDATE users SET onboarding_step = ? WHERE id = ?', [$step, $userId]);
                    $this->onboarding($user, (int) $chatId, $step);
                    break;
                case 'onboarding.finish':
                    $this->database->execute('UPDATE users SET onboarding_step = 7, onboarding_completed_at = CURRENT_TIMESTAMP WHERE id = ?', [$userId]);
                    $this->mainMenu($user, (int) $chatId);
                    break;
                case 'hosts.list':
                    $this->hosts($user, (int) $chatId);
                    break;
                case 'host.add':
                    $this->sessions->set($userId, 'waiting_for_host');
                    $this->send((int) $chatId, $this->translator->get('hosts.add_prompt', $language), $this->cancelKeyboard($userId, $language));
                    break;
                case 'host.store':
                    $this->completeHostAdd($user, (int) $chatId, (bool) ($payload['store'] ?? false));
                    break;
                case 'host.health':
                    $health = $this->accountService->health($userId, (int) ($payload['account_id'] ?? 0));
                    $this->send((int) $chatId, $this->translator->get('hosts.health_ok', $language, ['latency' => $health['latency_ms']]), $this->hostKeyboard($userId, $language, (int) $payload['account_id']));
                    break;
                case 'host.open':
                    $accountId = (int) ($payload['account_id'] ?? 0);
                    $account = $this->accounts->getOwned($userId, $accountId);
                    $this->sessions->setActiveAccount($userId, $accountId);
                    $this->send((int) $chatId, '🖥 <b>' . htmlspecialchars((string) ($account['label'] ?: $account['hostname']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>', $this->hostKeyboard($userId, $language, $accountId));
                    break;
                case 'file.upload_start':
                    $accountId = (int) ($payload['account_id'] ?? 0);
                    $this->accounts->getOwned($userId, $accountId);
                    $this->sessions->set($userId, 'waiting_for_upload_path', ['account_id' => $accountId], $accountId);
                    $this->send((int) $chatId, $this->translator->get('files.upload_path_prompt', $language), $this->cancelKeyboard($userId, $language));
                    break;
                case 'file.upload_mode':
                    $session = $this->sessions->get($userId);
                    if ($session === null || $session['state'] !== 'waiting_for_upload_mode') {
                        throw new AppException('Telegram upload setup expired. Start again.', 410, 'upload_session_expired', [], 'files.upload');
                    }
                    $mode = ($payload['mode'] ?? null) === 'overwrite' ? 'overwrite' : 'rename';
                    $uploadPayload = $session['payload'];
                    $uploadPayload['collision'] = $mode;
                    $this->sessions->set($userId, 'waiting_for_upload_document', $uploadPayload, (int) $uploadPayload['account_id']);
                    $this->send((int) $chatId, $this->translator->get('files.upload_document_prompt', $language), $this->cancelKeyboard($userId, $language));
                    break;
                case 'file.download_start':
                    $accountId = (int) ($payload['account_id'] ?? 0);
                    $this->accounts->getOwned($userId, $accountId);
                    $this->sessions->set($userId, 'waiting_for_download_path', ['account_id' => $accountId], $accountId);
                    $this->send((int) $chatId, $this->translator->get('files.download_path_prompt', $language), $this->cancelKeyboard($userId, $language));
                    break;
                case 'host.remove_preview':
                    $accountId = (int) ($payload['account_id'] ?? 0);
                    $account = $this->accounts->getOwned($userId, $accountId);
                    $nonce = $this->confirmations->issue($userId, $accountId, 'host.remove', (string) $accountId, ['hostname' => $account['hostname'], 'effect' => 'credentials_and_metadata_removed']);
                    $this->send((int) $chatId, "🔴 " . htmlspecialchars((string) $account['hostname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n" . $this->translator->get('common.confirm', $language) . '?', [[['text' => '🔴 ' . $this->translator->get('common.remove', $language), 'callback_data' => $this->callbacks->create($userId, 'host.remove_confirm', ['account_id' => $accountId, 'nonce' => $nonce])]]]);
                    break;
                case 'host.remove_confirm':
                    $accountId = (int) ($payload['account_id'] ?? 0);
                    $this->confirmations->consume((string) ($payload['nonce'] ?? ''), $userId, $accountId, 'host.remove', (string) $accountId);
                    $this->accounts->remove($userId, $accountId);
                    $this->send((int) $chatId, $this->translator->get('hosts.removed', $language));
                    $this->hosts($user, (int) $chatId);
                    break;
                case 'help.search':
                    $this->sessions->set($userId, 'waiting_for_help_search');
                    $this->send((int) $chatId, $this->translator->get('help.prompt', $language), $this->cancelKeyboard($userId, $language));
                    break;
                case 'help.topic':
                    $topic = $this->help->topic((string) ($payload['slug'] ?? 'start.overview'), $language);
                    $keyboard = [];
                    foreach (array_slice((array) ($topic['related_topics'] ?? []), 0, 6) as $related) {
                        $keyboard[] = [['text' => '↗ ' . (string) $related, 'callback_data' => $this->callbacks->create($userId, 'help.topic', ['slug' => (string) $related])]];
                    }
                    $keyboard[] = [['text' => '⌂ ' . $this->translator->get('common.main_menu', $language), 'callback_data' => $this->callbacks->create($userId, 'menu.main')]];
                    $this->send((int) $chatId, $this->formatHelp($topic, $language), $keyboard);
                    break;
                case 'settings.language':
                    $selected = ($payload['language'] ?? null) === 'en' ? 'en' : 'fa';
                    $this->settings->language($userId, $selected);
                    $user['language'] = $selected;
                    $this->settingsMenu($user, (int) $chatId);
                    break;
                case 'settings.open':
                    $this->settingsMenu($user, (int) $chatId);
                    break;
                case 'security.open':
                    $this->securityMenu($userId, $language, (int) $chatId);
                    break;
                case 'settings.mode':
                    $this->settings->uxMode($userId, ($payload['mode'] ?? null) === 'advanced' ? 'advanced' : 'beginner');
                    $this->send((int) $chatId, $this->translator->get('settings.saved', $language));
                    break;
                case 'menu.main':
                    $this->mainMenu($user, (int) $chatId);
                    break;
                case 'session.cancel':
                    $this->sessions->cancel($userId);
                    $this->send((int) $chatId, $this->translator->get('cancel.done', $language));
                    break;
                default:
                    throw new AppException('The selected bot action is not supported.', 404, 'callback_action_not_found');
            }
        } catch (\Throwable $exception) {
            try {
                $this->telegram->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $exception instanceof AppException && $exception->safeCode === 'callback_expired' ? $this->translator->get('error.expired_button', $language) : $this->translator->get('error.generic', $language), 'show_alert' => true]);
            } catch (\Throwable) {
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $user */
    private function command(string $command, array $user, int $chatId, ?string $argument = null): void
    {
        $userId = (int) $user['id'];
        $language = $this->language($user);
        switch ($command) {
            case 'start':
                if ($argument !== null && $this->openStartTarget($userId, $language, $chatId, $argument)) {
                    break;
                }
                if ($user['onboarding_completed_at'] === null) {
                    $this->startOnboarding($user, $chatId);
                } else {
                    $this->mainMenu($user, $chatId);
                }
                break;
            case 'panel':
                $this->openPanel($userId, $language, $chatId);
                break;
            case 'hosts':
                $this->hosts($user, $chatId);
                break;
            case 'help':
                $this->helpMenu($userId, $language, $chatId);
                break;
            case 'security':
                $this->securityMenu($userId, $language, $chatId);
                break;
            case 'settings':
                $this->settingsMenu($user, $chatId);
                break;
            case 'cancel':
                $this->cancel($userId, $language, $chatId);
                break;
            case 'admin':
                $this->adminMenu($user, $chatId);
                break;
            default:
                $this->mainMenu($user, $chatId);
        }
    }

    /** @param array<string,mixed> $user
     * @param array{state:?string,payload:array<string,mixed>,active_account_id:?int} $session
     */
    private function stateInput(array $user, int $chatId, int $messageId, string $text, array $session): void
    {
        $userId = (int) $user['id'];
        $language = $this->language($user);
        $payload = $session['payload'];
        switch ($session['state']) {
            case 'waiting_for_host':
                if ($text === '' || strlen($text) > 512) {
                    throw new AppException('cPanel address is invalid.', 422, 'invalid_cpanel_url', [], 'hosts.add');
                }
                $payload['host'] = $text;
                $this->sessions->set($userId, 'waiting_for_cpanel_username', $payload);
                $this->send($chatId, $this->translator->get('hosts.username_prompt', $language), $this->cancelKeyboard($userId, $language));
                break;
            case 'waiting_for_cpanel_username':
                if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $text)) {
                    throw new AppException('cPanel username format is invalid.', 422, 'invalid_cpanel_credentials', [], 'hosts.add');
                }
                $payload['username'] = $text;
                $this->sessions->set($userId, 'waiting_for_token', $payload);
                $this->send($chatId, $this->translator->get('hosts.token_prompt', $language), $this->cancelKeyboard($userId, $language));
                break;
            case 'waiting_for_token':
                try {
                    if (strlen($text) < 8 || strlen($text) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $text)) {
                        throw new AppException('API token format is invalid.', 422, 'invalid_cpanel_credentials', [], 'hosts.add');
                    }
                    $payload['token'] = $text;
                    $this->sessions->set($userId, 'waiting_for_store_mode', $payload);
                } finally {
                    try {
                        if ($messageId > 0) {
                            $this->telegram->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
                        }
                    } catch (\Throwable) {
                    }
                }
                $this->send($chatId, $this->translator->get('hosts.storage_prompt', $language), [[
                    ['text' => $this->translator->get('hosts.store', $language), 'callback_data' => $this->callbacks->create($userId, 'host.store', ['store' => true])],
                    ['text' => $this->translator->get('hosts.temporary', $language), 'callback_data' => $this->callbacks->create($userId, 'host.store', ['store' => false])],
                ]]);
                break;
            case 'waiting_for_upload_path':
                $accountId = (int) ($payload['account_id'] ?? 0);
                $listing = $this->files->browse($userId, $accountId, $text, 1, 10);
                $payload['directory'] = $listing['path'];
                $this->sessions->set($userId, 'waiting_for_upload_mode', $payload, $accountId);
                $this->send($chatId, $this->translator->get('files.upload_mode_prompt', $language), [[
                    ['text' => $this->translator->get('files.rename_automatically', $language), 'callback_data' => $this->callbacks->create($userId, 'file.upload_mode', ['mode' => 'rename'])],
                    ['text' => $this->translator->get('files.overwrite', $language), 'callback_data' => $this->callbacks->create($userId, 'file.upload_mode', ['mode' => 'overwrite'])],
                ], [
                    ['text' => $this->translator->get('common.cancel', $language), 'callback_data' => $this->callbacks->create($userId, 'session.cancel')],
                ]]);
                break;
            case 'waiting_for_download_path':
                $this->sendFileToTelegram($user, $chatId, (int) ($payload['account_id'] ?? 0), $text);
                break;
            case 'waiting_for_help_search':
                $results = $this->help->search($text, $language, null, 8);
                $this->sessions->cancel($userId);
                if ($results === []) {
                    $this->send($chatId, $this->translator->get('help.no_results', $language));
                    break;
                }
                $keyboard = [];
                foreach ($results as $result) {
                    $keyboard[] = [[
                        'text' => (string) $result['title'],
                        'callback_data' => $this->callbacks->create($userId, 'help.topic', ['slug' => $result['slug']]),
                    ]];
                }
                $this->send($chatId, '📖 ' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $keyboard);
                break;
            default:
                $this->sessions->cancel($userId);
                $this->mainMenu($user, $chatId);
        }
    }

    /** @param array<string,mixed> $user */
    private function completeHostAdd(array $user, int $chatId, bool $store): void
    {
        $userId = (int) $user['id'];
        $language = $this->language($user);
        $session = $this->sessions->get($userId);
        if ($session === null || $session['state'] !== 'waiting_for_store_mode') {
            throw new AppException('Host setup session expired. Start again.', 410, 'host_setup_expired', [], 'hosts.add');
        }
        $payload = $session['payload'];
        $this->telegram->call('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
        try {
            $result = $this->accountService->add($userId, (string) ($payload['host'] ?? ''), (string) ($payload['username'] ?? ''), (string) ($payload['token'] ?? ''), $store);
            $this->sessions->setActiveAccount($userId, $result['id']);
            $this->sessions->cancel($userId);
            $this->send($chatId, $this->translator->get('hosts.added', $language), $this->hostKeyboard($userId, $language, $result['id']));
        } finally {
            unset($payload['token']);
        }
    }

    /** @param array<string,mixed> $user
     *  @param array<string,mixed> $document
     *  @param array{state:?string,payload:array<string,mixed>,active_account_id:?int} $session
     */
    private function documentInput(array $user, int $chatId, int $messageId, array $document, array $session): void
    {
        $userId = (int) $user['id'];
        $language = $this->language($user);
        $payload = $session['payload'];
        $accountId = (int) ($payload['account_id'] ?? 0);
        $directory = (string) ($payload['directory'] ?? '');
        $collision = ($payload['collision'] ?? null) === 'overwrite' ? 'overwrite' : 'rename';
        $queued = $this->uploads->enqueue($userId, $accountId, $chatId, $messageId, $document, $directory, $collision, $language);
        $this->sessions->cancel($userId);
        $this->send(
            $chatId,
            $this->translator->get('files.upload_queued', $language, ['job' => $queued['job_id'], 'name' => htmlspecialchars($queued['filename'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')]),
            $this->hostKeyboard($userId, $language, $accountId)
        );
    }

    /** @param array<string,mixed> $user */
    private function sendFileToTelegram(array $user, int $chatId, int $accountId, string $remotePath): void
    {
        $userId = (int) $user['id'];
        $language = $this->language($user);
        $info = $this->files->info($userId, $accountId, $remotePath);
        if (in_array(strtolower((string) ($info['type'] ?? '')), ['dir', 'directory'], true)) {
            throw new AppException('Select a file, not a directory. Use the Mini App to create an archive first.', 422, 'download_directory_not_supported', [], 'files.download');
        }
        $safePath = (string) $info['path'];
        $issued = $this->downloads->issue($userId, $accountId, $safePath, 900, $chatId, $language);
        $this->sessions->cancel($userId);
        $this->send($chatId, $this->translator->get('files.download_queued', $language, ['job' => $issued['job_id']]), $this->hostKeyboard($userId, $language, $accountId));
    }

    /** @param array<string,mixed> $user */
    private function startOnboarding(array $user, int $chatId): void
    {
        if ((int) ($user['onboarding_step'] ?? 0) === 0) {
            $this->send($chatId, $this->translator->get('start.choose_language', 'fa'), [[
                ['text' => $this->translator->get('language.fa', 'fa'), 'callback_data' => $this->callbacks->create((int) $user['id'], 'language.select', ['language' => 'fa'])],
                ['text' => $this->translator->get('language.en', 'en'), 'callback_data' => $this->callbacks->create((int) $user['id'], 'language.select', ['language' => 'en'])],
            ]]);
            return;
        }
        $this->onboarding($user, $chatId, (int) $user['onboarding_step']);
    }

    /** @param array<string,mixed> $user */
    private function onboarding(array $user, int $chatId, int $step): void
    {
        $userId = (int) $user['id'];
        $language = $this->language($user);
        $buttons = [];
        if ($step > 1) {
            $buttons[] = ['text' => '◀️ ' . $this->translator->get('common.previous', $language), 'callback_data' => $this->callbacks->create($userId, 'onboarding.goto', ['step' => $step - 1])];
        }
        $buttons[] = ['text' => ($step === 7 ? '✅ ' . $this->translator->get('common.finish', $language) : $this->translator->get('common.next', $language) . ' ▶️'), 'callback_data' => $this->callbacks->create($userId, $step === 7 ? 'onboarding.finish' : 'onboarding.goto', ['step' => $step + 1])];
        $keyboard = [$buttons];
        if ($step < 7) {
            $keyboard[] = [['text' => $this->translator->get('common.skip', $language), 'callback_data' => $this->callbacks->create($userId, 'onboarding.finish')]];
        }
        $this->send($chatId, $this->translator->get('onboarding.' . $step, $language), $keyboard);
    }

    /** @param array<string,mixed> $user */
    private function mainMenu(array $user, int $chatId): void
    {
        $language = $this->language($user);
        $userId = (int) $user['id'];
        $keyboard = [
            [['text' => $this->translator->get('menu.panel', $language), 'web_app' => ['url' => $this->miniAppUrl]]],
            [
                ['text' => $this->translator->get('menu.hosts', $language), 'callback_data' => $this->callbacks->create($userId, 'hosts.list')],
                ['text' => $this->translator->get('menu.add_host', $language), 'callback_data' => $this->callbacks->create($userId, 'host.add')],
            ],
            [
                ['text' => $this->translator->get('menu.help', $language), 'callback_data' => $this->callbacks->create($userId, 'help.search')],
                ['text' => $this->translator->get('menu.security', $language), 'callback_data' => $this->callbacks->create($userId, 'security.open')],
            ],
            [['text' => $this->translator->get('menu.status', $language), 'web_app' => ['url' => $this->panelUrl('dashboard')]]],
            [
                ['text' => $this->translator->get('menu.settings', $language), 'callback_data' => $this->callbacks->create($userId, 'settings.open')],
            ],
        ];
        $this->send($chatId, $this->translator->get('start.menu', $language), $keyboard);
    }

    /** @param array<string,mixed> $user */
    private function hosts(array $user, int $chatId): void
    {
        $userId = (int) $user['id'];
        $language = $this->language($user);
        $hosts = $this->accounts->listOwned($userId);
        $keyboard = [[['text' => $this->translator->get('menu.add_host', $language), 'callback_data' => $this->callbacks->create($userId, 'host.add')]]];
        $lines = [$this->translator->get('hosts.title', $language)];
        foreach ($hosts as $host) {
            $lines[] = "\n• <b>" . htmlspecialchars((string) ($host['label'] ?: $host['hostname']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b> — ' . htmlspecialchars((string) $host['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $keyboard[] = [
                ['text' => '✅ ' . $this->translator->get('common.health', $language), 'callback_data' => $this->callbacks->create($userId, 'host.health', ['account_id' => (int) $host['id']])],
                ['text' => '🌐 ' . $this->translator->get('common.open', $language), 'callback_data' => $this->callbacks->create($userId, 'host.open', ['account_id' => (int) $host['id']])],
            ];
            $keyboard[] = [['text' => '🗑 ' . $this->translator->get('common.remove', $language), 'callback_data' => $this->callbacks->create($userId, 'host.remove_preview', ['account_id' => (int) $host['id']])]];
        }
        if ($hosts === []) {
            $lines[] = "\n" . $this->translator->get('empty.hosts', $language);
        }
        $keyboard[] = [
            ['text' => '❓ ' . $this->translator->get('common.help', $language), 'callback_data' => $this->callbacks->create($userId, 'help.topic', ['slug' => 'hosts.add'])],
            ['text' => '⌂ ' . $this->translator->get('common.main_menu', $language), 'callback_data' => $this->callbacks->create($userId, 'menu.main')],
        ];
        $this->send($chatId, implode('', $lines), $keyboard);
    }

    private function openPanel(int $userId, string $language, int $chatId): void
    {
        $this->send($chatId, $this->translator->get('menu.panel', $language), [[['text' => $this->translator->get('menu.panel', $language), 'web_app' => ['url' => $this->panelUrl('dashboard')]]]]);
    }

    private function helpMenu(int $userId, string $language, int $chatId): void
    {
        $this->send($chatId, $this->translator->get('help.prompt', $language), [
            [['text' => $this->translator->get('help.search', $language), 'callback_data' => $this->callbacks->create($userId, 'help.search')]],
            [['text' => $this->translator->get('common.open', $language), 'web_app' => ['url' => $this->panelUrl('help')]]],
            [['text' => '⌂ ' . $this->translator->get('common.main_menu', $language), 'callback_data' => $this->callbacks->create($userId, 'menu.main')]],
        ]);
    }

    private function securityMenu(int $userId, string $language, int $chatId): void
    {
        $data = $this->security->dashboard($userId);
        $text = $this->translator->get('security.title', $language) . "\n\n" . $this->translator->get('security.summary', $language, ['hosts' => count($data['hosts']), 'sessions' => count($data['sessions']), 'alerts' => count(array_filter($data['alerts'], static fn (array $event): bool => $event['acknowledged_at'] === null))]);
        $this->send($chatId, $text, [
            [['text' => $this->translator->get('common.open', $language), 'web_app' => ['url' => $this->panelUrl('security')]],
             ['text' => '❓ ' . $this->translator->get('common.help', $language), 'callback_data' => $this->callbacks->create($userId, 'help.topic', ['slug' => 'security.token'])]],
            [['text' => '⌂ ' . $this->translator->get('common.main_menu', $language), 'callback_data' => $this->callbacks->create($userId, 'menu.main')]],
        ]);
    }

    /** @param array<string,mixed> $user */
    private function settingsMenu(array $user, int $chatId): void
    {
        $userId = (int) $user['id'];
        $language = $this->language($user);
        $this->send($chatId, $this->translator->get('settings.title', $language), [
            [
                ['text' => $this->translator->get('language.fa', $language), 'callback_data' => $this->callbacks->create($userId, 'settings.language', ['language' => 'fa'])],
                ['text' => $this->translator->get('language.en', $language), 'callback_data' => $this->callbacks->create($userId, 'settings.language', ['language' => 'en'])],
            ],
            [
                ['text' => $this->translator->get('settings.beginner', $language), 'callback_data' => $this->callbacks->create($userId, 'settings.mode', ['mode' => 'beginner'])],
                ['text' => $this->translator->get('settings.advanced', $language), 'callback_data' => $this->callbacks->create($userId, 'settings.mode', ['mode' => 'advanced'])],
            ],
            [['text' => $this->translator->get('settings.onboarding', $language), 'callback_data' => $this->callbacks->create($userId, 'onboarding.goto', ['step' => 1])]],
            [
                ['text' => '❓ ' . $this->translator->get('common.help', $language), 'callback_data' => $this->callbacks->create($userId, 'help.topic', ['slug' => 'settings.overview'])],
                ['text' => '⌂ ' . $this->translator->get('common.main_menu', $language), 'callback_data' => $this->callbacks->create($userId, 'menu.main')],
            ],
        ]);
    }

    /** @param array<string,mixed> $user */
    private function adminMenu(array $user, int $chatId): void
    {
        $language = $this->language($user);
        $data = $this->admin->dashboard((int) $user['id']);
        $totals = $data['totals'];
        $text = $this->translator->get('admin.summary', $language, ['users' => (int) $totals['users'], 'hosts' => (int) $totals['hosts'], 'queue' => (int) $totals['queued'], 'security' => (int) $totals['security_alerts']]);
        $this->send($chatId, $text, [[
            ['text' => $this->translator->get('admin.open', $language), 'web_app' => ['url' => $this->panelUrl('admin')]],
        ]]);
    }

    private function openStartTarget(int $userId, string $language, int $chatId, string $argument): bool
    {
        if ($argument === 'panel') {
            $this->openPanel($userId, $language, $chatId);
            return true;
        }
        if (!preg_match('/^(host|files|file|databases|database|deploy)_([1-9][0-9]{0,18})$/', $argument, $match)) {
            return false;
        }
        $accountId = (int) $match[2];
        $account = $this->accounts->getOwned($userId, $accountId);
        $this->sessions->setActiveAccount($userId, $accountId);
        if ($match[1] === 'host') {
            $this->send($chatId, '🖥 <b>' . htmlspecialchars((string) ($account['label'] ?: $account['hostname']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>', $this->hostKeyboard($userId, $language, $accountId));
            return true;
        }
        $route = match ($match[1]) {
            'files', 'file' => 'files',
            'databases', 'database' => 'databases',
            'deploy' => 'deploy',
        };
        $this->send($chatId, $this->translator->get('menu.panel', $language), [[['text' => $this->translator->get('common.open', $language), 'web_app' => ['url' => $this->panelUrl($route, $accountId)]]]]);
        return true;
    }

    /** @param array<string,mixed> $update */
    private function sendOperationError(array $update, AppException $exception): void
    {
        $source = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : (is_array($update['message'] ?? null) ? $update['message'] : []);
        $message = is_array($source['message'] ?? null) ? $source['message'] : $source;
        $from = is_array($source['from'] ?? null) ? $source['from'] : [];
        $chatId = filter_var($message['chat']['id'] ?? null, FILTER_VALIDATE_INT);
        if ($chatId === false || !isset($from['id'])) {
            return;
        }
        $user = $this->users->upsertTelegram($from);
        $userId = (int) $user['id'];
        $language = $this->language($user);
        if (in_array($exception->safeCode, ['rate_limit_exceeded', 'ssrf_target_blocked', 'callback_invalid', 'callback_expired', 'invalid_cpanel_credentials', 'cpanel_auth_failed'], true)) {
            try {
                $metadata = ['code' => $exception->safeCode, 'channel' => 'telegram'];
                $fingerprint = hash('sha256', $exception->safeCode . '|' . $userId . '|telegram');
                $this->database->execute('INSERT INTO security_events (user_id, event_type, severity, fingerprint, metadata_json) VALUES (?, ?, ?, ?, ?)', [$userId, $exception->safeCode, $exception->httpStatus === 429 ? 'warning' : 'danger', $fingerprint, json_encode($metadata, JSON_THROW_ON_ERROR)]);
                $parameters = json_encode(['code' => $exception->safeCode], JSON_THROW_ON_ERROR);
                $this->database->execute("INSERT INTO notifications (user_id, type, title_key, body_key, parameters_json) SELECT id, 'security_alert', 'notification.security_title', 'notification.security_event', ? FROM users WHERE is_super_admin = 1 AND status = 'active'", [$parameters]);
            } catch (\Throwable) {
            }
        }
        $keyboard = [[['text' => $this->translator->get('common.help', $language), 'web_app' => ['url' => $this->panelUrl('help', null, $exception->helpSlug)]]]];
        $this->send($chatId, $this->translator->get('error.guidance', $language, ['code' => $exception->safeCode]), $keyboard);
    }

    private function panelUrl(string $route, ?int $accountId = null, ?string $helpSlug = null): string
    {
        $query = ['route' => $route];
        if ($accountId !== null) {
            $query['host'] = $accountId;
        }
        if ($route === 'help' && $helpSlug !== null && $helpSlug !== '') {
            $query['slug'] = $helpSlug;
        }
        return rtrim($this->miniAppUrl, '?&') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function cancel(int $userId, string $language, int $chatId): void
    {
        $active = $this->sessions->get($userId) !== null;
        $this->sessions->cancel($userId);
        $this->send($chatId, $this->translator->get($active ? 'cancel.done' : 'cancel.none', $language));
    }

    /** @return list<list<array<string,mixed>>> */
    private function hostKeyboard(int $userId, string $language, int $accountId): array
    {
        return [
            [
                ['text' => $this->translator->get('section.files', $language), 'web_app' => ['url' => $this->panelUrl('files', $accountId)]],
                ['text' => $this->translator->get('section.databases', $language), 'web_app' => ['url' => $this->panelUrl('databases', $accountId)]],
            ],
            [
                ['text' => $this->translator->get('section.domains', $language), 'web_app' => ['url' => $this->panelUrl('domains', $accountId)]],
                ['text' => $this->translator->get('section.email', $language), 'web_app' => ['url' => $this->panelUrl('email', $accountId)]],
            ],
            [
                ['text' => $this->translator->get('section.ssl', $language), 'web_app' => ['url' => $this->panelUrl('ssl', $accountId)]],
                ['text' => $this->translator->get('section.cron', $language), 'web_app' => ['url' => $this->panelUrl('cron', $accountId)]],
            ],
            [
                ['text' => $this->translator->get('section.backup', $language), 'web_app' => ['url' => $this->panelUrl('backups', $accountId)]],
                ['text' => $this->translator->get('section.deploy', $language), 'web_app' => ['url' => $this->panelUrl('deploy', $accountId)]],
            ],
            [
                ['text' => $this->translator->get('section.usage', $language), 'web_app' => ['url' => $this->panelUrl('usage', $accountId)]],
                ['text' => $this->translator->get('section.logs', $language), 'web_app' => ['url' => $this->panelUrl('logs', $accountId)]],
            ],
            [
                ['text' => $this->translator->get('section.php', $language), 'web_app' => ['url' => $this->panelUrl('php', $accountId)]],
                ['text' => $this->translator->get('menu.security', $language), 'web_app' => ['url' => $this->panelUrl('security', $accountId)]],
            ],
            [
                ['text' => $this->translator->get('files.telegram_upload', $language), 'callback_data' => $this->callbacks->create($userId, 'file.upload_start', ['account_id' => $accountId])],
                ['text' => $this->translator->get('files.telegram_download', $language), 'callback_data' => $this->callbacks->create($userId, 'file.download_start', ['account_id' => $accountId])],
            ],
            [['text' => $this->translator->get('common.health', $language), 'callback_data' => $this->callbacks->create($userId, 'host.health', ['account_id' => $accountId])]],
            [
                ['text' => $this->translator->get('common.help', $language), 'callback_data' => $this->callbacks->create($userId, 'help.topic', ['slug' => 'start.overview'])],
                ['text' => '⌂ ' . $this->translator->get('common.main_menu', $language), 'callback_data' => $this->callbacks->create($userId, 'menu.main')],
            ],
        ];
    }

    /** @return list<list<array<string,mixed>>> */
    private function cancelKeyboard(int $userId, string $language): array
    {
        return [[['text' => $this->translator->get('common.cancel', $language), 'callback_data' => $this->callbacks->create($userId, 'session.cancel')]]];
    }

    /** @param array<string,mixed> $topic */
    private function formatHelp(array $topic, string $language): string
    {
        $icon = match ($topic['warning_level'] ?? 'info') {
            'danger' => '🔴',
            'warning' => '🟠',
            default => '🔵',
        };
        return $icon . ' <b>' . htmlspecialchars((string) $topic['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n\n" . htmlspecialchars((string) $topic['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param list<list<array<string,mixed>>> $keyboard */
    private function send(int $chatId, string $text, array $keyboard = []): void
    {
        $parameters = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($keyboard !== []) {
            $parameters['reply_markup'] = ['inline_keyboard' => $keyboard];
        }
        $this->telegram->call('sendMessage', $parameters);
    }

    /** @param array<string,mixed> $user */
    private function language(array $user): string
    {
        return ($user['language'] ?? null) === 'en' ? 'en' : 'fa';
    }

    /** @param array<string,mixed> $user */
    private function accessBlock(array $user): ?string
    {
        $language = $this->language($user);
        if (($user['status'] ?? 'active') !== 'active') {
            return $this->translator->get('access.inactive', $language);
        }
        if ((bool) ($user['is_super_admin'] ?? false)) {
            return null;
        }
        $row = $this->database->one("SELECT setting_value FROM settings WHERE setting_key = 'maintenance'");
        $setting = $row === null ? null : json_decode((string) $row['setting_value'], true);
        if (!is_array($setting) || ($setting['enabled'] ?? false) !== true) {
            return null;
        }
        $custom = trim((string) ($setting['message_' . $language] ?? ''));
        return $custom !== '' ? mb_substr($custom, 0, 500) : $this->translator->get('maintenance.default', $language);
    }
}
