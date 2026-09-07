<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Database;
use App\Core\SecretMasker;

final class AuditLogger
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function record(
        ?int $userId,
        ?int $accountId,
        string $action,
        string $result,
        ?string $targetType = null,
        ?string $targetRef = null,
        array $metadata = [],
        ?string $ip = null,
        ?string $requestId = null,
    ): void {
        $safeMetadata = SecretMasker::mask($metadata);
        $safeTarget = $targetRef !== null && strlen($targetRef) > 512 ? substr($targetRef, 0, 512) : $targetRef;
        $packedIp = $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : null;
        $this->database->execute(
            'INSERT INTO audit_logs (user_id, account_id, action, target_type, target_ref, result, request_id, ip_address, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $accountId, $action, $targetType, $safeTarget, $result, $requestId, $packedIp, json_encode($safeMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
        );
        if ($userId !== null && $result === 'success' && !in_array($action, ['file.browse', 'host.health'], true)) {
            $this->database->execute(
                'INSERT INTO recent_actions (user_id, account_id, action, resource_type, resource_ref, metadata_json) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $accountId, $action, $targetType, $safeTarget, json_encode($safeMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
            );
        }
    }

    /** @return list<array<string,mixed>> */
    public function recentForUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        return $this->database->all('SELECT id, account_id, action, target_type, target_ref, result, metadata_json, created_at FROM audit_logs WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit, [$userId]);
    }
}
