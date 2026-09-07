<?php

declare(strict_types=1);

namespace App\Queue;

use App\Core\AppException;
use App\Core\Logger;
use App\Deployment\DeploymentRecoveryService;

final class QueueWorker
{
    /** @param array<string,JobHandler> $handlers */
    public function __construct(
        private readonly QueueService $queue,
        private readonly array $handlers,
        private readonly Logger $logger,
        private readonly ?DeploymentRecoveryService $deploymentRecovery = null,
    ) {
    }

    public function runOnce(string $queueName = 'default'): bool
    {
        if ($queueName === 'default') {
            $this->deploymentRecovery?->recover(3);
        }
        $job = $this->queue->claim($queueName);
        if ($job === null) {
            return false;
        }
        try {
            $handler = $this->handlers[(string) $job['job_type']] ?? null;
            if (!$handler instanceof JobHandler) {
                throw new AppException('No worker handles this job type.', 500, 'queue_handler_missing');
            }
            $result = $handler->handle(new JobContext($this->queue, $job), $this->queue->payload($job));
            $this->queue->complete($job, $result);
        } catch (\Throwable $exception) {
            $code = $exception instanceof AppException ? $exception->safeCode : 'queue_job_failed';
            $this->logger->error($exception, ['job_id' => $job['id'], 'job_type' => $job['job_type']]);
            $this->queue->fail($job, $code, $exception instanceof AppException ? $exception->getMessage() : 'The queued operation failed.');
        }
        return true;
    }

    public function work(string $queueName = 'default', int $maxJobs = 20, int $maxSeconds = 50): int
    {
        $started = time();
        $processed = 0;
        while ($processed < max(1, $maxJobs) && time() - $started < max(1, $maxSeconds) && $this->runOnce($queueName)) {
            $processed++;
        }
        return $processed;
    }
}
