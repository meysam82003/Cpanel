<?php

declare(strict_types=1);

namespace App\Cron;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Cpanel\UapiClient;

final class CronService
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly CronExpressionValidator $validator,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function list(int $userId, int $accountId): array
    {
        $result = $this->cpanel->callLegacyApi2($this->accounts->connection($userId, $accountId), 'Cron', 'listcron');
        $jobs = array_values(array_filter(is_array($result['data']) ? $result['data'] : [], static fn (mixed $item): bool => is_array($item) && isset($item['command'])));
        return ['jobs' => $jobs, 'provider_api' => 'api2_no_uapi_equivalent'];
    }

    /** @return array<string,mixed> */
    public function create(int $userId, int $accountId, string $expression, string $command, bool $dangerousConfirmed = false): array
    {
        $schedule = $this->validator->validate($expression);
        $inspection = $this->validator->inspectCommand($command);
        if ($inspection['dangerous'] && !$dangerousConfirmed) {
            throw new AppException('This cron command matches dangerous command patterns and requires explicit confirmation.', 409, 'dangerous_cron_confirmation_required', ['reasons' => $inspection['reasons']], 'cron.commands');
        }
        $result = $this->cpanel->callLegacyApi2($this->accounts->connection($userId, $accountId), 'Cron', 'add_line', $schedule + ['command' => trim($command)], false);
        $this->audit->record($userId, $accountId, 'cron.create', 'success', 'cron', hash('sha256', $command), ['schedule' => $expression, 'dangerous_patterns' => $inspection['reasons'], 'provider_api' => 'api2_no_uapi_equivalent']);
        return ['schedule' => $schedule, 'provider_api' => 'api2_no_uapi_equivalent', 'cpanel' => $result['data']];
    }

    /** @param array<string,string|int> $existing
     *  @return array<string,mixed>
     */
    public function update(int $userId, int $accountId, string $lineKey, array $existing, string $expression, string $command, bool $dangerousConfirmed = false): array
    {
        $lineKey = trim($lineKey);
        if ($lineKey === '' || strlen($lineKey) > 128 || preg_match('/^[A-Za-z0-9_-]+$/', $lineKey) !== 1) {
            throw new AppException('Cron line identifier is invalid.', 422, 'invalid_cron_id', [], 'cron.overview');
        }
        $schedule = $this->validator->validate($expression);
        $inspection = $this->validator->inspectCommand($command);
        if ($inspection['dangerous'] && !$dangerousConfirmed) {
            throw new AppException('This cron command requires explicit confirmation.', 409, 'dangerous_cron_confirmation_required', ['reasons' => $inspection['reasons']], 'cron.commands');
        }
        $old = $this->validatedExisting($existing);
        $params = $schedule + [
            'command' => trim($command),
            'linekey' => $lineKey,
        ];
        $result = $this->cpanel->callLegacyApi2($this->accounts->connection($userId, $accountId), 'Cron', 'edit_line', $params, false);
        $this->audit->record($userId, $accountId, 'cron.update', 'success', 'cron', $lineKey, ['schedule' => $expression, 'dangerous_patterns' => $inspection['reasons'], 'provider_api' => 'api2_no_uapi_equivalent']);
        return ['provider_api' => 'api2_no_uapi_equivalent', 'cpanel' => $result['data']];
    }

    /** @param array<string,string|int> $existing
     *  @return array<string,mixed>
     */
    public function delete(int $userId, int $accountId, int $lineKey, array $existing): array
    {
        $old = $this->validatedExisting($existing);
        if ($lineKey < 1) {
            throw new AppException('Cron line number is invalid.', 422, 'invalid_cron_id', [], 'cron.overview');
        }
        $result = $this->cpanel->callLegacyApi2($this->accounts->connection($userId, $accountId), 'Cron', 'remove_line', ['line' => $lineKey], false);
        $this->audit->record($userId, $accountId, 'cron.delete', 'success', 'cron', (string) $lineKey, ['schedule' => implode(' ', array_slice(array_values($old), 0, 5)), 'provider_api' => 'api2_no_uapi_equivalent']);
        return ['provider_api' => 'api2_no_uapi_equivalent', 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function setEnabled(int $userId, int $accountId, string $lineKey, array $existing, bool $enabled): array
    {
        $old = $this->validatedExisting($existing);
        $command = (string) $old['command'];
        $prefix = '#TCM_DISABLED# ';
        if ($enabled && str_starts_with($command, $prefix)) {
            $command = substr($command, strlen($prefix));
        } elseif (!$enabled && !str_starts_with($command, $prefix)) {
            $command = $prefix . $command;
        }
        $expression = implode(' ', [$old['minute'], $old['hour'], $old['day'], $old['month'], $old['weekday']]);
        return $this->update($userId, $accountId, $lineKey, $existing, $expression, $command, true);
    }

    /** @param array<string,string|int> $existing
     * @return array{minute:string,hour:string,day:string,month:string,weekday:string,command:string}
     */
    private function validatedExisting(array $existing): array
    {
        $expression = implode(' ', array_map('strval', [$existing['minute'] ?? '', $existing['hour'] ?? '', $existing['day'] ?? '', $existing['month'] ?? '', $existing['weekday'] ?? '']));
        $schedule = $this->validator->validate($expression);
        $this->validator->inspectCommand((string) ($existing['command'] ?? ''));
        return $schedule + ['command' => (string) $existing['command']];
    }
}
