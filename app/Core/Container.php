<?php

declare(strict_types=1);

namespace App\Core;

use App\Accounts\AccountRepository;
use App\Accounts\AccountService;
use App\Accounts\UserRepository;
use App\Accounts\UserSettingsService;
use App\Admin\AdminService;
use App\Admin\BroadcastJobHandler;
use App\Audit\AuditLogger;
use App\Backup\BackupJobTracker;
use App\Backup\BackupService;
use App\Cpanel\CapabilityDetector;
use App\Cpanel\UapiClient;
use App\Cron\CronExpressionValidator;
use App\Cron\CronService;
use App\Database\CpanelDatabaseService;
use App\Database\DatabaseDumpWriter;
use App\Database\DataManagerService;
use App\Database\DirectDatabaseConnectionService;
use App\Database\SqlConsoleService;
use App\Database\SqlConsoleCoordinator;
use App\Database\SqlExportJobHandler;
use App\Database\SqlImportJobHandler;
use App\Database\SqlSafetyAnalyzer;
use App\Database\SqlTransferService;
use App\Deployment\CpanelDeploymentFilesystem;
use App\Deployment\DeploymentBackupVerifier;
use App\Deployment\DeploymentFilesystem;
use App\Deployment\DeploymentJobHandler;
use App\Deployment\DeploymentRollbackExecutor;
use App\Deployment\DeploymentPackageService;
use App\Deployment\DeploymentRecoveryService;
use App\Deployment\DeploymentService;
use App\Deployment\HealthCheckService;
use App\Deployment\RollbackJobHandler;
use App\Deployment\ZipPackageValidator;
use App\Domains\DomainService;
use App\Email\EmailService;
use App\FileManager\ArchiveCreateJobHandler;
use App\FileManager\ArchiveExtractJobHandler;
use App\FileManager\ArchiveSafetyValidator;
use App\FileManager\ArchiveService;
use App\FileManager\DownloadService;
use App\FileManager\FileDownloadJobHandler;
use App\FileManager\FileManagerService;
use App\FileManager\FileVersionService;
use App\FileManager\TelegramFileUploadJobHandler;
use App\FileManager\TelegramUploadService;
use App\Help\HelpService;
use App\Logs\LogViewerService;
use App\Notifications\NotificationService;
use App\PHP\PhpSettingsService;
use App\Plans\PlanGuard;
use App\Queue\CleanupService;
use App\Queue\QueueService;
use App\Queue\QueueWorker;
use App\Security\ConfirmationService;
use App\Security\Crypto;
use App\Security\HostValidator;
use App\Security\MiniAppSessionService;
use App\Security\OperationLockService;
use App\Security\PathGuard;
use App\Security\RateLimiter;
use App\Security\SecurityCenterService;
use App\Security\TelegramInitDataValidator;
use App\SSL\SslService;
use App\Telegram\BotHandler;
use App\Telegram\BotSessionService;
use App\Telegram\CallbackStateService;
use App\Telegram\TelegramClient;
use App\Usage\UsageService;
use App\Http\UploadReceiver;
use App\Support\ErrorGuidanceService;

final class Container
{
    /** @var array<class-string,object> */
    private array $instances = [];

    public function __construct(public readonly string $root)
    {
    }

    /** @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        $service = match ($id) {
            Database::class => new Database(Config::database()),
            Logger::class => new Logger($this->root . '/storage/logs'),
            Crypto::class => new Crypto($this->encryptionKeys(), Env::int('ENCRYPTION_CURRENT_VERSION', 1)),
            Translator::class => new Translator($this->root . '/resources/lang'),
            HostValidator::class => new HostValidator(Env::bool('ALLOW_PRIVATE_CPANEL_HOSTS'), $this->ports()),
            PathGuard::class => new PathGuard(),
            UapiClient::class => new UapiClient($this->get(HostValidator::class), $this->get(Logger::class), Env::int('CPANEL_CONNECT_TIMEOUT', 8), Env::int('CPANEL_REQUEST_TIMEOUT', 30)),
            AuditLogger::class => new AuditLogger($this->get(Database::class)),
            AccountRepository::class => new AccountRepository($this->get(Database::class), $this->get(Crypto::class)),
            UserRepository::class => new UserRepository($this->get(Database::class)),
            CapabilityDetector::class => new CapabilityDetector($this->get(Database::class), $this->get(UapiClient::class)),
            AccountService::class => new AccountService($this->get(Database::class), $this->get(AccountRepository::class), $this->get(HostValidator::class), $this->get(UapiClient::class), $this->get(CapabilityDetector::class), $this->get(AuditLogger::class)),
            FileVersionService::class => new FileVersionService($this->get(Database::class), $this->get(UapiClient::class)),
            FileManagerService::class => new FileManagerService($this->get(AccountRepository::class), $this->get(UapiClient::class), $this->get(PathGuard::class), $this->get(FileVersionService::class), $this->get(AuditLogger::class)),
            ArchiveSafetyValidator::class => new ArchiveSafetyValidator(),
            ArchiveService::class => new ArchiveService($this->get(Database::class), $this->get(AccountRepository::class), $this->get(PlanGuard::class), $this->get(PathGuard::class), $this->get(QueueService::class), $this->get(AuditLogger::class)),
            DownloadService::class => new DownloadService($this->get(Database::class), $this->get(AccountRepository::class), $this->get(PathGuard::class), $this->get(UapiClient::class), $this->get(AuditLogger::class), $this->get(QueueService::class), $this->root . '/storage/downloads', Env::int('MAX_DOWNLOAD_BYTES', 104_857_600)),
            TelegramUploadService::class => new TelegramUploadService($this->get(Database::class), $this->get(AccountRepository::class), $this->get(PlanGuard::class), $this->get(PathGuard::class), $this->get(QueueService::class), Env::int('TELEGRAM_DOWNLOAD_MAX_BYTES', 20_000_000)),
            CpanelDatabaseService::class => new CpanelDatabaseService($this->get(AccountRepository::class), $this->get(UapiClient::class), $this->get(AuditLogger::class)),
            DirectDatabaseConnectionService::class => new DirectDatabaseConnectionService($this->get(Database::class), $this->get(AccountRepository::class), $this->get(CpanelDatabaseService::class), $this->get(HostValidator::class), $this->get(Crypto::class), $this->get(AuditLogger::class)),
            DataManagerService::class => new DataManagerService($this->get(DirectDatabaseConnectionService::class), $this->get(AuditLogger::class)),
            SqlSafetyAnalyzer::class => new SqlSafetyAnalyzer(),
            SqlConsoleService::class => new SqlConsoleService($this->get(Database::class), $this->get(DirectDatabaseConnectionService::class), $this->get(SqlSafetyAnalyzer::class), $this->get(Crypto::class), $this->get(AuditLogger::class)),
            SqlConsoleCoordinator::class => new SqlConsoleCoordinator($this->get(Database::class), $this->get(DirectDatabaseConnectionService::class), $this->get(DatabaseDumpWriter::class), $this->get(SqlConsoleService::class), $this->root . '/storage/backups'),
            DatabaseDumpWriter::class => new DatabaseDumpWriter(),
            QueueService::class => new QueueService($this->get(Database::class), $this->get(Crypto::class), Env::int('QUEUE_STALE_AFTER_SECONDS', 3600)),
            SqlTransferService::class => new SqlTransferService($this->get(QueueService::class), $this->root . '/storage/temp', [$this->root . '/storage/backups', $this->root . '/storage/downloads']),
            DomainService::class => new DomainService($this->get(AccountRepository::class), $this->get(UapiClient::class), $this->get(AuditLogger::class)),
            EmailService::class => new EmailService($this->get(AccountRepository::class), $this->get(UapiClient::class), $this->get(AuditLogger::class)),
            SslService::class => new SslService($this->get(AccountRepository::class), $this->get(UapiClient::class), $this->get(AuditLogger::class)),
            CronExpressionValidator::class => new CronExpressionValidator(),
            CronService::class => new CronService($this->get(AccountRepository::class), $this->get(UapiClient::class), $this->get(CronExpressionValidator::class), $this->get(AuditLogger::class)),
            UsageService::class => new UsageService($this->get(AccountRepository::class), $this->get(UapiClient::class)),
            PhpSettingsService::class => new PhpSettingsService($this->get(AccountRepository::class), $this->get(UapiClient::class), $this->get(AuditLogger::class)),
            LogViewerService::class => new LogViewerService($this->get(AccountRepository::class), $this->get(UapiClient::class), $this->get(PathGuard::class), $this->root . '/storage/temp'),
            BackupJobTracker::class => new BackupJobTracker($this->get(Database::class), $this->get(AuditLogger::class), $this->get(NotificationService::class)),
            BackupService::class => new BackupService($this->get(Database::class), $this->get(AccountRepository::class), $this->get(UapiClient::class), $this->get(FileManagerService::class), $this->get(ArchiveService::class), $this->get(SqlTransferService::class), $this->get(DeploymentService::class), $this->get(BackupJobTracker::class), $this->get(AuditLogger::class), $this->get(NotificationService::class), [$this->root . '/storage/backups', $this->root . '/storage/downloads']),
            OperationLockService::class => new OperationLockService($this->get(Database::class)),
            ZipPackageValidator::class => new ZipPackageValidator($this->get(ArchiveSafetyValidator::class)),
            HealthCheckService::class => new HealthCheckService(new HostValidator(false, [443], 443)),
            DeploymentFilesystem::class => new CpanelDeploymentFilesystem($this->get(AccountRepository::class), $this->get(UapiClient::class), $this->get(FileManagerService::class), $this->get(PathGuard::class)),
            DeploymentBackupVerifier::class => new DeploymentBackupVerifier($this->get(DeploymentFilesystem::class), $this->get(ZipPackageValidator::class), $this->root . '/storage/temp', Env::int('MAX_DEPLOY_BACKUP_VERIFY_BYTES', 1_073_741_824)),
            DeploymentRollbackExecutor::class => new DeploymentRollbackExecutor($this->get(DeploymentFilesystem::class), $this->get(DeploymentBackupVerifier::class)),
            DeploymentRecoveryService::class => new DeploymentRecoveryService($this->get(Database::class), $this->get(QueueService::class), $this->get(AuditLogger::class), $this->root . '/storage/temp'),
            DeploymentPackageService::class => new DeploymentPackageService($this->get(Database::class), $this->get(AccountRepository::class), $this->get(ZipPackageValidator::class)),
            DeploymentService::class => new DeploymentService($this->get(Database::class), $this->get(AccountRepository::class), $this->get(PathGuard::class), $this->get(ZipPackageValidator::class), $this->get(QueueService::class), $this->get(AuditLogger::class), $this->root . '/storage/temp'),
            ConfirmationService::class => new ConfirmationService($this->get(Database::class)),
            RateLimiter::class => new RateLimiter($this->get(Database::class)),
            MiniAppSessionService::class => new MiniAppSessionService($this->get(Database::class), Env::require('SESSION_SECRET'), Env::int('MINIAPP_SESSION_TTL', 3600)),
            TelegramInitDataValidator::class => new TelegramInitDataValidator($this->get(Database::class), Env::require('TELEGRAM_BOT_TOKEN'), Env::int('TELEGRAM_INITDATA_TTL', 900)),
            PlanGuard::class => new PlanGuard($this->get(Database::class)),
            UserSettingsService::class => new UserSettingsService($this->get(Database::class), $this->get(UserRepository::class)),
            SecurityCenterService::class => new SecurityCenterService($this->get(Database::class), $this->get(UserRepository::class), $this->get(AccountRepository::class), $this->get(MiniAppSessionService::class)),
            AdminService::class => new AdminService($this->get(Database::class), $this->get(QueueService::class), $this->get(AuditLogger::class), $this->get(TelegramClient::class), $this->get(Crypto::class), $this->root),
            HelpService::class => new HelpService($this->get(Database::class)),
            TelegramClient::class => new TelegramClient(Env::require('TELEGRAM_BOT_TOKEN')),
            BotSessionService::class => new BotSessionService($this->get(Database::class), $this->get(Crypto::class)),
            CallbackStateService::class => new CallbackStateService($this->get(Database::class), Env::require('CALLBACK_SECRET')),
            NotificationService::class => new NotificationService($this->get(Database::class), $this->get(Translator::class), $this->get(TelegramClient::class)),
            UploadReceiver::class => new UploadReceiver($this->root . '/storage/temp'),
            ErrorGuidanceService::class => new ErrorGuidanceService(),
            BotHandler::class => new BotHandler($this->get(Database::class), $this->get(TelegramClient::class), $this->get(UserRepository::class), $this->get(AccountRepository::class), $this->get(AccountService::class), $this->get(UserSettingsService::class), $this->get(SecurityCenterService::class), $this->get(AdminService::class), $this->get(HelpService::class), $this->get(Translator::class), $this->get(BotSessionService::class), $this->get(CallbackStateService::class), $this->get(ConfirmationService::class), $this->get(RateLimiter::class), rtrim((string) Config::app('url'), '/') . '/miniapp/', $this->get(FileManagerService::class), $this->get(DownloadService::class), $this->get(TelegramUploadService::class)),
            CleanupService::class => new CleanupService($this->get(Database::class), $this->root . '/storage'),
            QueueWorker::class => new QueueWorker($this->get(QueueService::class), $this->jobHandlers(), $this->get(Logger::class), $this->get(DeploymentRecoveryService::class), $this->get(BackupJobTracker::class)),
            default => throw new AppException('Service is not registered: ' . $id, 500, 'service_not_registered'),
        };
        $this->instances[$id] = $service;
        return $service;
    }

    /** @return array<string,\App\Queue\JobHandler> */
    private function jobHandlers(): array
    {
        return [
            'sql.import' => new SqlImportJobHandler($this->get(DirectDatabaseConnectionService::class), $this->get(DatabaseDumpWriter::class), $this->get(Database::class), $this->get(AuditLogger::class), $this->root . '/storage/temp', $this->root . '/storage/backups'),
            'sql.export' => new SqlExportJobHandler($this->get(DirectDatabaseConnectionService::class), $this->get(DatabaseDumpWriter::class), $this->get(Database::class), $this->get(AuditLogger::class), $this->root . '/storage/downloads'),
            'file.download' => new FileDownloadJobHandler($this->get(DownloadService::class), $this->get(Database::class), $this->get(TelegramClient::class), $this->get(Translator::class), (string) Config::app('url'), Env::int('TELEGRAM_SEND_MAX_BYTES', 50_000_000)),
            'telegram.file_upload' => new TelegramFileUploadJobHandler($this->get(Database::class), $this->get(TelegramUploadService::class), $this->get(FileManagerService::class), $this->get(PlanGuard::class), $this->get(TelegramClient::class), $this->get(AuditLogger::class), $this->root . '/storage/temp'),
            'file.archive_create' => new ArchiveCreateJobHandler($this->get(ArchiveService::class), $this->get(FileManagerService::class)),
            'file.archive_extract' => new ArchiveExtractJobHandler($this->get(ArchiveService::class), $this->get(FileManagerService::class), $this->get(AccountRepository::class), $this->get(UapiClient::class), $this->get(ArchiveSafetyValidator::class), $this->root . '/storage/temp', Env::int('MAX_ARCHIVE_INSPECTION_BYTES', 268_435_456), Env::int('MAX_ARCHIVE_EXPANDED_BYTES', 1_073_741_824), Env::int('MAX_ARCHIVE_FILES', 10_000), Env::int('MAX_ARCHIVE_EXPANSION_RATIO', 200), Env::int('MAX_ARCHIVE_TOP_LEVEL_ENTRIES', 500)),
            'deployment.run' => new DeploymentJobHandler($this->get(Database::class), $this->get(AccountRepository::class), $this->get(DeploymentFilesystem::class), $this->get(ZipPackageValidator::class), $this->get(DeploymentBackupVerifier::class), $this->get(HealthCheckService::class), $this->get(DeploymentRollbackExecutor::class), $this->get(OperationLockService::class), $this->get(PathGuard::class), $this->get(AuditLogger::class), $this->root . '/storage/temp', $this->root . '/storage/temp'),
            'deployment.rollback' => new RollbackJobHandler($this->get(Database::class), $this->get(DeploymentRollbackExecutor::class), $this->get(OperationLockService::class), $this->get(AuditLogger::class)),
            'admin.broadcast' => new BroadcastJobHandler($this->get(Database::class), $this->get(QueueService::class), $this->get(TelegramClient::class)),
        ];
    }

    /** @return array<int,string> */
    private function encryptionKeys(): array
    {
        $keys = [];
        for ($version = 1; $version <= 20; $version++) {
            $value = Env::get('ENCRYPTION_KEY_V' . $version);
            if (is_string($value) && $value !== '') {
                $keys[$version] = Crypto::decodeKey($value);
            }
        }
        return $keys;
    }

    /** @return list<int> */
    private function ports(): array
    {
        $ports = array_map('intval', explode(',', (string) Env::get('CPANEL_ALLOWED_PORTS', '2083,443')));
        return array_values(array_unique(array_filter($ports, static fn (int $port): bool => $port >= 1 && $port <= 65535)));
    }
}
