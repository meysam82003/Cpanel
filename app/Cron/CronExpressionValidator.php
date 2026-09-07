<?php

declare(strict_types=1);

namespace App\Cron;

use App\Core\AppException;

final class CronExpressionValidator
{
    /** @return array{minute:string,hour:string,day:string,month:string,weekday:string} */
    public function validate(string $expression): array
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (!is_array($parts) || count($parts) !== 5) {
            throw new AppException('A cron expression must contain exactly five fields.', 422, 'invalid_cron_expression', [], 'cron.overview');
        }
        $limits = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        foreach ($parts as $index => $field) {
            if (!$this->validField($field, $limits[$index][0], $limits[$index][1])) {
                throw new AppException('One of the cron schedule fields is invalid.', 422, 'invalid_cron_expression', ['field' => $index + 1], 'cron.overview');
            }
        }
        return array_combine(['minute', 'hour', 'day', 'month', 'weekday'], $parts);
    }

    /** @return array{dangerous:bool,reasons:list<string>} */
    public function inspectCommand(string $command): array
    {
        $command = trim($command);
        if ($command === '' || strlen($command) > 4096 || str_contains($command, "\0") || str_contains($command, "\n")) {
            throw new AppException('Cron command is empty, too long, or contains invalid characters.', 422, 'invalid_cron_command', [], 'cron.commands');
        }
        $patterns = [
            'recursive_delete' => '/(?:^|[;&|])\s*(?:sudo\s+)?rm\s+(?:-[a-z]*r[a-z]*f?|-[a-z]*f[a-z]*r)\b/i',
            'disk_write' => '/\b(?:dd|mkfs(?:\.[a-z0-9]+)?|fdisk|parted)\b/i',
            'remote_pipe' => '/\b(?:curl|wget)\b[^\n]*(?:\||>)\s*(?:sh|bash|php|python|perl)\b/i',
            'permission_wide' => '/\bchmod\s+(?:-R\s+)?777\b/i',
            'fork_bomb' => '/:\(\)\s*\{\s*:\|:\s*&\s*\}\s*;\s*:/',
        ];
        $reasons = [];
        foreach ($patterns as $reason => $pattern) {
            if (preg_match($pattern, $command) === 1) {
                $reasons[] = $reason;
            }
        }
        return ['dangerous' => $reasons !== [], 'reasons' => $reasons];
    }

    private function validField(string $field, int $minimum, int $maximum): bool
    {
        if (!preg_match('/^[0-9*,\/-]+$/', $field)) {
            return false;
        }
        foreach (explode(',', $field) as $segment) {
            [$base, $step] = array_pad(explode('/', $segment, 2), 2, null);
            if ($step !== null && (!ctype_digit($step) || (int) $step < 1 || (int) $step > $maximum)) {
                return false;
            }
            if ($base === '*') {
                continue;
            }
            if (str_contains($base, '-')) {
                [$start, $end] = array_pad(explode('-', $base, 2), 2, '');
                if (!ctype_digit($start) || !ctype_digit($end) || (int) $start < $minimum || (int) $end > $maximum || (int) $start > (int) $end) {
                    return false;
                }
            } elseif (!ctype_digit($base) || (int) $base < $minimum || (int) $base > $maximum) {
                return false;
            }
        }
        return true;
    }
}
