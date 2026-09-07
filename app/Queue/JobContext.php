<?php

declare(strict_types=1);

namespace App\Queue;

final class JobContext
{
    /** @param array<string,mixed> $job */
    public function __construct(private readonly QueueService $queue, public readonly array $job)
    {
    }

    public function progress(int $percentage, string $message): void
    {
        $this->queue->progress((int) $this->job['id'], (string) $this->job['reservation_token'], $percentage, $message);
    }

    public function userId(): ?int
    {
        return $this->job['user_id'] === null ? null : (int) $this->job['user_id'];
    }

    public function accountId(): ?int
    {
        return $this->job['account_id'] === null ? null : (int) $this->job['account_id'];
    }

    public function leaseToken(): string
    {
        return (string) $this->job['reservation_token'];
    }
}
