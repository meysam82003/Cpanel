<?php

declare(strict_types=1);

use App\Backup\BackupService;
use App\Core\AppException;
use App\Core\Container;
use App\Core\Database;
use App\Cron\CronService;
use App\Deployment\DeploymentPackageService;
use App\Deployment\DeploymentService;
use App\Domains\DomainService;
use App\Email\EmailService;
use App\FileManager\DownloadService;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\UploadReceiver;
use App\Logs\LogViewerService;
use App\PHP\PhpSettingsService;
use App\Plans\PlanGuard;
use App\Security\ConfirmationService;
use App\SSL\SslService;
use App\Usage\UsageService;

return static function (ApiKernel $api, Container $container): void {
    $domains = $container->get(DomainService::class);
    $email = $container->get(EmailService::class);
    $ssl = $container->get(SslService::class);
    $cron = $container->get(CronService::class);
    $backups = $container->get(BackupService::class);
    $usage = $container->get(UsageService::class);
    $logs = $container->get(LogViewerService::class);
    $php = $container->get(PhpSettingsService::class);
    $deployments = $container->get(DeploymentService::class);
    $deploymentPackages = $container->get(DeploymentPackageService::class);
    $uploads = $container->get(UploadReceiver::class);
    $downloads = $container->get(DownloadService::class);
    $plans = $container->get(PlanGuard::class);
    $confirmations = $container->get(ConfirmationService::class);
    $database = $container->get(Database::class);

    $api->route('GET', '/api/v1/hosts/{account}/domains', static fn (Request $request, array $params, array $session): array => $domains->list((int) $session['user_id'], (int) $params['account']));
    $api->route('POST', '/api/v1/hosts/{account}/domains', static fn (Request $request, array $params, array $session): array => $domains->add((int) $session['user_id'], (int) $params['account'], (string) $request->input('domain', ''), (string) $request->input('document_root', '')), 20);
    $api->route('DELETE', '/api/v1/hosts/{account}/domains/{domain}', static function (Request $request, array $params, array $session) use ($domains, $confirmations): array {
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'domain.delete', (string) $params['domain']);
        return $domains->remove((int) $session['user_id'], (int) $params['account'], (string) $params['domain']);
    }, 10);
    $api->route('POST', '/api/v1/hosts/{account}/subdomains', static fn (Request $request, array $params, array $session): array => $domains->addSubdomain((int) $session['user_id'], (int) $params['account'], (string) $request->input('subdomain', ''), (string) $request->input('parent_domain', ''), (string) $request->input('document_root', '')), 20);
    $api->route('DELETE', '/api/v1/hosts/{account}/subdomains/{domain}', static function (Request $request, array $params, array $session) use ($domains, $confirmations): array {
        $target = (string) $params['domain'];
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'subdomain.delete', $target);
        return $domains->removeSubdomain((int) $session['user_id'], (int) $params['account'], $target);
    }, 10);
    $api->route('GET', '/api/v1/hosts/{account}/redirects', static fn (Request $request, array $params, array $session): array => $domains->redirects((int) $session['user_id'], (int) $params['account']));
    $api->route('POST', '/api/v1/hosts/{account}/redirects', static fn (Request $request, array $params, array $session): array => $domains->addRedirect((int) $session['user_id'], (int) $params['account'], (string) $request->input('domain', ''), (string) $request->input('source', '/'), (string) $request->input('destination', ''), (int) $request->input('status', 301), filter_var($request->input('wildcard', false), FILTER_VALIDATE_BOOL)));
    $api->route('DELETE', '/api/v1/hosts/{account}/redirects', static function (Request $request, array $params, array $session) use ($domains, $confirmations): array {
        $domain = (string) $request->input('domain', '');
        $source = (string) $request->input('source', '/');
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'redirect.delete', $domain . ':' . $source);
        return $domains->removeRedirect((int) $session['user_id'], (int) $params['account'], $domain, $source);
    });
    $api->route('GET', '/api/v1/hosts/{account}/dns/{domain}', static fn (Request $request, array $params, array $session): array => $domains->dnsZone((int) $session['user_id'], (int) $params['account'], (string) $params['domain']));
    $api->route('PATCH', '/api/v1/hosts/{account}/dns/{domain}', static function (Request $request, array $params, array $session) use ($domains, $confirmations): array {
        $changes = $request->input('changes', []);
        if (!is_array($changes)) {
            throw new AppException('DNS changes must be a list.', 422, 'invalid_dns_changes', [], 'domains.dns');
        }
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'dns.edit', (string) $params['domain']);
        return $domains->editDnsZone((int) $session['user_id'], (int) $params['account'], (string) $params['domain'], array_values($changes));
    }, 10);

    $api->route('GET', '/api/v1/hosts/{account}/email/accounts', static fn (Request $request, array $params, array $session): array => $email->accounts((int) $session['user_id'], (int) $params['account'], (int) $request->input('page', 1), (int) $request->input('per_page', 50)));
    $api->route('POST', '/api/v1/hosts/{account}/email/accounts', static fn (Request $request, array $params, array $session): array => $email->create((int) $session['user_id'], (int) $params['account'], (string) $request->input('local_part', ''), (string) $request->input('domain', ''), (string) $request->input('password', ''), (int) $request->input('quota_mib', 1024)), 20);
    $api->route('DELETE', '/api/v1/hosts/{account}/email/accounts', static function (Request $request, array $params, array $session) use ($email, $confirmations): array {
        $target = (string) $request->input('email', '');
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'email.delete', $target);
        return $email->delete((int) $session['user_id'], (int) $params['account'], $target);
    }, 15);
    $api->route('PATCH', '/api/v1/hosts/{account}/email/password', static fn (Request $request, array $params, array $session): array => $email->changePassword((int) $session['user_id'], (int) $params['account'], (string) $request->input('email', ''), (string) $request->input('password', '')), 15);
    $api->route('PATCH', '/api/v1/hosts/{account}/email/quota', static fn (Request $request, array $params, array $session): array => $email->changeQuota((int) $session['user_id'], (int) $params['account'], (string) $request->input('email', ''), (int) $request->input('quota_mib', 1024)));
    $api->route('GET', '/api/v1/hosts/{account}/email/forwarders', static fn (Request $request, array $params, array $session): array => $email->forwarders((int) $session['user_id'], (int) $params['account']));
    $api->route('POST', '/api/v1/hosts/{account}/email/forwarders', static fn (Request $request, array $params, array $session): array => $email->addForwarder((int) $session['user_id'], (int) $params['account'], (string) $request->input('source', ''), (string) $request->input('destination', '')));
    $api->route('DELETE', '/api/v1/hosts/{account}/email/forwarders', static function (Request $request, array $params, array $session) use ($email, $confirmations): array {
        $source = (string) $request->input('source', '');
        $destination = (string) $request->input('destination', '');
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'email.forwarder_delete', $source . ':' . $destination);
        return $email->deleteForwarder((int) $session['user_id'], (int) $params['account'], $source, $destination);
    });
    $api->route('GET', '/api/v1/hosts/{account}/email/autoresponders', static fn (Request $request, array $params, array $session): array => $email->autoresponders((int) $session['user_id'], (int) $params['account']));
    $api->route('PUT', '/api/v1/hosts/{account}/email/autoresponders', static fn (Request $request, array $params, array $session): array => $email->saveAutoresponder((int) $session['user_id'], (int) $params['account'], (string) $request->input('email', ''), (string) $request->input('subject', ''), (string) $request->input('body', ''), (int) $request->input('start', 0), (int) $request->input('stop', 0), (int) $request->input('interval_hours', 8)));
    $api->route('DELETE', '/api/v1/hosts/{account}/email/autoresponders', static function (Request $request, array $params, array $session) use ($email, $confirmations): array {
        $target = (string) $request->input('email', '');
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'email.autoresponder_delete', $target);
        return $email->deleteAutoresponder((int) $session['user_id'], (int) $params['account'], $target);
    });

    $api->route('GET', '/api/v1/hosts/{account}/ssl', static fn (Request $request, array $params, array $session): array => $ssl->status((int) $session['user_id'], (int) $params['account']));
    $api->route('GET', '/api/v1/hosts/{account}/ssl/autossl', static fn (Request $request, array $params, array $session): array => $ssl->autoSslEligibility((int) $session['user_id'], (int) $params['account']));
    $api->route('POST', '/api/v1/hosts/{account}/ssl/autossl', static function (Request $request, array $params, array $session) use ($ssl, $confirmations): array {
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'ssl.autossl', 'autossl');
        return $ssl->runAutoSsl((int) $session['user_id'], (int) $params['account']);
    }, 5);
    $api->route('POST', '/api/v1/hosts/{account}/ssl/certificates/inspect', static function (Request $request, array $params, array $session) use ($ssl): array {
        $selected = $request->input('domains', []);
        if (!is_array($selected)) {
            throw new AppException('SSL domains must be a list.', 422, 'invalid_ssl_domains', [], 'ssl.autossl');
        }
        return $ssl->certificateInfo((int) $session['user_id'], (int) $params['account'], array_values(array_map('strval', $selected)));
    });

    $api->route('GET', '/api/v1/hosts/{account}/cron', static fn (Request $request, array $params, array $session): array => $cron->list((int) $session['user_id'], (int) $params['account']));
    $api->route('POST', '/api/v1/hosts/{account}/cron', static function (Request $request, array $params, array $session) use ($cron, $confirmations): array {
        $command = (string) $request->input('command', '');
        $confirmed = false;
        if ($request->input('confirmation') !== null) {
            $confirmations->consume((string) $request->input('confirmation'), (int) $session['user_id'], (int) $params['account'], 'cron.save', 'cron:' . hash('sha256', $command));
            $confirmed = true;
        }
        return $cron->create((int) $session['user_id'], (int) $params['account'], (string) $request->input('expression', ''), $command, $confirmed);
    }, 20);
    $api->route('PUT', '/api/v1/hosts/{account}/cron/{line}', static function (Request $request, array $params, array $session) use ($cron, $confirmations): array {
        $existing = $request->input('existing', []);
        if (!is_array($existing)) {
            throw new AppException('Existing cron definition is required.', 422, 'invalid_cron_expression', [], 'cron.overview');
        }
        $command = (string) $request->input('command', '');
        $confirmed = false;
        if ($request->input('confirmation') !== null) {
            $confirmations->consume((string) $request->input('confirmation'), (int) $session['user_id'], (int) $params['account'], 'cron.save', 'cron:' . hash('sha256', $command));
            $confirmed = true;
        }
        return $cron->update((int) $session['user_id'], (int) $params['account'], (string) $params['line'], $existing, (string) $request->input('expression', ''), $command, $confirmed);
    });
    $api->route('DELETE', '/api/v1/hosts/{account}/cron/{line}', static function (Request $request, array $params, array $session) use ($cron, $confirmations): array {
        $existing = $request->input('existing', []);
        if (!is_array($existing)) {
            throw new AppException('Existing cron definition is required.', 422, 'invalid_cron_expression', [], 'cron.overview');
        }
        $target = 'cron:' . $params['line'];
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'cron.delete', $target);
        return $cron->delete((int) $session['user_id'], (int) $params['account'], (int) $params['line'], $existing);
    }, 15);
    $api->route('PATCH', '/api/v1/hosts/{account}/cron/{line}/enabled', static function (Request $request, array $params, array $session) use ($cron): array {
        $existing = $request->input('existing', []);
        if (!is_array($existing)) {
            throw new AppException('Existing cron definition is required.', 422, 'invalid_cron_expression', [], 'cron.overview');
        }
        return $cron->setEnabled((int) $session['user_id'], (int) $params['account'], (string) $params['line'], $existing, filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOL));
    });

    $api->route('GET', '/api/v1/hosts/{account}/backups', static function (Request $request, array $params, array $session) use ($backups, $plans): array {
        $plans->feature((int) $session['user_id'], 'backup_enabled');
        return $backups->list((int) $session['user_id'], (int) $params['account']);
    });
    $api->route('POST', '/api/v1/hosts/{account}/backups/full', static function (Request $request, array $params, array $session) use ($backups, $plans): array {
        $plans->feature((int) $session['user_id'], 'backup_enabled');
        return $backups->generateFull((int) $session['user_id'], (int) $params['account']);
    }, 3);
    $api->route('POST', '/api/v1/hosts/{account}/backups/database', static function (Request $request, array $params, array $session) use ($backups, $plans): array {
        $plans->feature((int) $session['user_id'], 'backup_enabled');
        $tables = $request->input('tables', []);
        if (!is_array($tables)) {
            throw new AppException('Backup tables must be a list.', 422, 'invalid_export_table');
        }
        return ['job_id' => $backups->database((int) $session['user_id'], (int) $params['account'], (string) $request->input('database', ''), array_values(array_map('strval', $tables)))];
    }, 10);
    $api->route('POST', '/api/v1/hosts/{account}/backups/directory', static function (Request $request, array $params, array $session) use ($backups, $plans): array {
        $plans->feature((int) $session['user_id'], 'backup_enabled');
        return $backups->directory((int) $session['user_id'], (int) $params['account'], (string) $request->input('directory', ''), $request->input('destination') === null ? null : (string) $request->input('destination'));
    }, 10);
    $api->route('POST', '/api/v1/hosts/{account}/backups/{backup}/restore', static function (Request $request, array $params, array $session) use ($backups, $confirmations): array {
        $target = (string) $params['backup'] . ':' . (string) $request->input('destination', '');
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'backup.restore', $target);
        return $backups->restoreDirectory((int) $session['user_id'], (int) $params['account'], (int) $params['backup'], (string) $request->input('destination', ''));
    }, 5);
    $api->route('DELETE', '/api/v1/hosts/{account}/backups/{backup}', static function (Request $request, array $params, array $session) use ($backups, $confirmations): array {
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'backup.delete', (string) $params['backup']);
        $backups->deleteRecord((int) $session['user_id'], (int) $params['account'], (int) $params['backup'], filter_var($request->input('delete_remote', false), FILTER_VALIDATE_BOOL));
        return ['removed' => true];
    }, 10);
    $api->route('GET', '/api/v1/hosts/{account}/backups/{backup}/download', static function (Request $request, array $params, array $session) use ($database, $downloads, $container): array|Response {
        $backup = $database->one('SELECT * FROM backups WHERE id = ? AND user_id = ? AND account_id = ?', [(int) $params['backup'], (int) $session['user_id'], (int) $params['account']]);
        if ($backup === null) {
            throw new AppException('Backup was not found or does not belong to you.', 404, 'backup_not_found', [], 'security.idor');
        }
        if (is_string($backup['provider_ref']) && str_starts_with($backup['provider_ref'], 'local:')) {
            $filename = substr($backup['provider_ref'], 6);
            if (basename($filename) !== $filename) {
                throw new AppException('Backup storage reference is invalid.', 500, 'backup_storage_invalid');
            }
            foreach ([$container->root . '/storage/backups/' . $filename, $container->root . '/storage/downloads/' . $filename] as $candidate) {
                if (is_file($candidate)) {
                    return Response::download($candidate, $filename, 'application/gzip', false);
                }
            }
            throw new AppException('Backup file has expired or is unavailable.', 410, 'backup_file_unavailable', [], 'backup.overview');
        }
        if (is_string($backup['remote_path']) && $backup['remote_path'] !== '') {
            return ['download' => $downloads->issue((int) $session['user_id'], (int) $params['account'], $backup['remote_path'])];
        }
        throw new AppException('This provider backup has no downloadable file reference yet.', 424, 'backup_download_unavailable', [], 'backup.overview');
    }, 20, true);

    $api->route('GET', '/api/v1/hosts/{account}/usage', static fn (Request $request, array $params, array $session): array => $usage->overview((int) $session['user_id'], (int) $params['account']), 60, true);
    $api->route('GET', '/api/v1/hosts/{account}/logs', static fn (Request $request, array $params, array $session): array => ['logs' => $logs->discover((int) $session['user_id'], (int) $params['account'])], 60, true);
    $api->route('GET', '/api/v1/hosts/{account}/logs/tail', static fn (Request $request, array $params, array $session): array => $logs->tail((int) $session['user_id'], (int) $params['account'], (string) $request->input('path', ''), (int) $request->input('lines', 100), $request->input('q') === null ? null : (string) $request->input('q')), 30, true);
    $api->route('GET', '/api/v1/hosts/{account}/php', static fn (Request $request, array $params, array $session): array => $php->information((int) $session['user_id'], (int) $params['account']));
    $api->route('PUT', '/api/v1/hosts/{account}/php/version', static function (Request $request, array $params, array $session) use ($php, $confirmations): array {
        $vhosts = $request->input('vhosts', []);
        if (!is_array($vhosts)) {
            throw new AppException('PHP virtual hosts must be a list.', 422, 'invalid_php_vhost', [], 'php.overview');
        }
        $version = (string) $request->input('version', '');
        $target = $version . ':' . implode(',', array_values(array_map('strval', $vhosts)));
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'php.version', $target);
        return $php->setVersion((int) $session['user_id'], (int) $params['account'], array_values(array_map('strval', $vhosts)), $version);
    }, 10);
    $api->route('PATCH', '/api/v1/hosts/{account}/php/ini', static function (Request $request, array $params, array $session) use ($php, $confirmations): array {
        $directives = $request->input('directives', []);
        if (!is_array($directives)) {
            throw new AppException('PHP INI directives must be an object.', 422, 'invalid_php_ini_update', [], 'php.overview');
        }
        $type = (string) $request->input('type', 'home');
        $vhost = $request->input('vhost') === null ? null : strtolower(trim((string) $request->input('vhost')));
        ksort($directives, SORT_STRING);
        $signature = '';
        foreach ($directives as $key => $value) {
            if (!is_scalar($value)) {
                throw new AppException('PHP INI directive values must be scalar.', 422, 'invalid_php_ini_update', [], 'php.overview');
            }
            $signature .= (string) $key . "\0" . (string) $value . "\n";
        }
        $target = $type . ':' . ($vhost ?? '') . ':' . hash('sha256', $signature);
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'php.ini', $target);
        return $php->setIni((int) $session['user_id'], (int) $params['account'], $type, $directives, $vhost);
    }, 10);

    $api->route('POST', '/api/v1/hosts/{account}/deployment-packages', static function (Request $request, array $params, array $session) use ($uploads, $plans, $deploymentPackages): array {
        $userId = (int) $session['user_id'];
        $plans->feature($userId, 'deployment_enabled');
        $entry = $request->files['file'] ?? null;
        if (!is_array($entry)) {
            throw new AppException('Select a deployment ZIP.', 422, 'upload_missing', [], 'deploy.package');
        }
        $plan = $plans->plan($userId);
        $file = $uploads->receive($entry, (int) $plan['max_upload_bytes'], ['zip']);
        try {
            $result = $deploymentPackages->register($userId, (int) $params['account'], $file['path'], $file['name']);
            $file = null;
            return $result;
        } finally {
            if (is_array($file)) {
                @unlink($file['path']);
            }
        }
    }, 8);
    $api->route('POST', '/api/v1/hosts/{account}/deployments', static function (Request $request, array $params, array $session) use ($plans, $deploymentPackages, $deployments, $confirmations): array {
        $userId = (int) $session['user_id'];
        $accountId = (int) $params['account'];
        $plans->feature($userId, 'deployment_enabled');
        $packageId = (int) $request->input('package_id', 0);
        $destination = (string) $request->input('destination', '');
        $healthCheckUrl = $request->input('health_check_url') === null ? null : trim((string) $request->input('health_check_url'));
        $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, 'deployment.run', $packageId . ':' . $destination);
        return $deployments->deployPackage($userId, $accountId, $packageId, $destination, $healthCheckUrl);
    }, 8);
    $api->route('GET', '/api/v1/hosts/{account}/deployments', static fn (Request $request, array $params, array $session): array => ['deployments' => $deployments->list((int) $session['user_id'], (int) $params['account'])]);
    $api->route('GET', '/api/v1/hosts/{account}/deployments/{deployment}', static fn (Request $request, array $params, array $session): array => $deployments->status((int) $session['user_id'], (int) $params['account'], (int) $params['deployment']));
    $api->route('POST', '/api/v1/hosts/{account}/deployments/{deployment}/rollback', static function (Request $request, array $params, array $session) use ($deployments, $confirmations): array {
        $userId = (int) $session['user_id'];
        $accountId = (int) $params['account'];
        $target = (string) $params['deployment'];
        $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, 'deployment.rollback', $target);
        return ['job_id' => $deployments->rollback($userId, $accountId, (int) $params['deployment'])];
    }, 8);
};
