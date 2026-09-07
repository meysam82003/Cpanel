<?php

declare(strict_types=1);

namespace App\Email;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Cpanel\UapiClient;

final class EmailService
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function accounts(int $userId, int $accountId, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Email', 'list_pops_with_disk', [
            'api.paginate.enable' => 1,
            'api.paginate.start' => ($page - 1) * $perPage + 1,
            'api.paginate.size' => $perPage,
            'api.sort.enable' => 1,
            'api.sort.a.field' => 'email',
            'api.sort.a.method' => 'lexicographic',
        ]);
        return ['accounts' => $result['data'], 'metadata' => $result['metadata'], 'page' => $page, 'per_page' => $perPage];
    }

    /** @return array<string,mixed> */
    public function create(int $userId, int $accountId, string $localPart, string $domain, string $password, int $quotaMiB = 1024): array
    {
        [$localPart, $domain] = $this->address($localPart . '@' . $domain);
        $this->password($password);
        $quotaMiB = max(1, min(1_048_576, $quotaMiB));
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Email', 'add_pop', [
            'email' => $localPart,
            'domain' => $domain,
            'password' => $password,
            'quota' => $quotaMiB,
            'send_welcome_email' => 0,
        ], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'email.create', 'success', 'email', $localPart . '@' . $domain, ['quota_mib' => $quotaMiB]);
        return ['email' => $localPart . '@' . $domain, 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function delete(int $userId, int $accountId, string $email): array
    {
        [$local, $domain] = $this->address($email);
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Email', 'delete_pop', ['email' => $local, 'domain' => $domain], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'email.delete', 'success', 'email', $local . '@' . $domain);
        return ['cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function changePassword(int $userId, int $accountId, string $email, string $password): array
    {
        [$local, $domain] = $this->address($email);
        $this->password($password);
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Email', 'passwd_pop', ['email' => $local, 'domain' => $domain, 'password' => $password], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'email.password_change', 'success', 'email', $local . '@' . $domain);
        return ['cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function changeQuota(int $userId, int $accountId, string $email, int $quotaMiB): array
    {
        [$local, $domain] = $this->address($email);
        $quotaMiB = max(1, min(1_048_576, $quotaMiB));
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Email', 'edit_pop_quota', ['email' => $local, 'domain' => $domain, 'quota' => $quotaMiB], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'email.quota_change', 'success', 'email', $local . '@' . $domain, ['quota_mib' => $quotaMiB]);
        return ['cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function forwarders(int $userId, int $accountId): array
    {
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Email', 'list_forwarders');
        return ['forwarders' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function addForwarder(int $userId, int $accountId, string $source, string $destination): array
    {
        [$local, $domain] = $this->address($source);
        if (filter_var($destination, FILTER_VALIDATE_EMAIL) === false) {
            throw new AppException('Forwarder destination is not a valid email address.', 422, 'invalid_forwarder', [], 'email.forwarders');
        }
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Email', 'add_forwarder', ['email' => $local, 'domain' => $domain, 'fwdopt' => 'fwd', 'fwdemail' => $destination], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'email.forwarder_add', 'success', 'email', $source, ['destination' => $destination]);
        return ['cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function deleteForwarder(int $userId, int $accountId, string $source, string $destination): array
    {
        [$local, $domain] = $this->address($source);
        if (filter_var($destination, FILTER_VALIDATE_EMAIL) === false) {
            throw new AppException('Forwarder destination is invalid.', 422, 'invalid_forwarder', [], 'email.forwarders');
        }
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Email', 'delete_forwarder', ['address' => $local . '@' . $domain, 'forwarder' => $destination], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'email.forwarder_delete', 'success', 'email', $source, ['destination' => $destination]);
        return ['cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function autoresponders(int $userId, int $accountId): array
    {
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Email', 'list_auto_responders');
        return ['autoresponders' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function saveAutoresponder(int $userId, int $accountId, string $email, string $subject, string $body, int $start, int $stop, int $intervalHours = 8): array
    {
        [$local, $domain] = $this->address($email);
        $subject = trim(mb_substr($subject, 0, 255));
        if ($subject === '' || $body === '' || strlen($body) > 65_536 || $start < 0 || $stop < 0 || ($stop !== 0 && $stop <= $start)) {
            throw new AppException('Autoresponder content or schedule is invalid.', 422, 'invalid_autoresponder', [], 'email.autoresponders');
        }
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Email', 'add_auto_responder', [
            'email' => $local,
            'domain' => $domain,
            'from' => $local . '@' . $domain,
            'subject' => $subject,
            'body' => $body,
            'is_html' => 0,
            'interval' => max(1, min(720, $intervalHours)),
            'start' => $start,
            'stop' => $stop,
        ], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'email.autoresponder_save', 'success', 'email', $local . '@' . $domain, ['start' => $start, 'stop' => $stop]);
        return ['cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function deleteAutoresponder(int $userId, int $accountId, string $email): array
    {
        [$local, $domain] = $this->address($email);
        $result = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Email', 'delete_auto_responder', ['email' => $local, 'domain' => $domain], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'email.autoresponder_delete', 'success', 'email', $local . '@' . $domain);
        return ['cpanel' => $result['data']];
    }

    /** @return array{0:string,1:string} */
    private function address(string $email): array
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new AppException('Enter a valid email address.', 422, 'invalid_email', [], 'email.accounts');
        }
        return explode('@', $email, 2);
    }

    private function password(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 128 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
            throw new AppException('Email passwords must be 12-128 characters without control characters.', 422, 'weak_email_password', [], 'email.accounts');
        }
    }
}
