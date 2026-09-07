<?php

declare(strict_types=1);

namespace App\Queue;

interface JobHandler
{
    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public function handle(JobContext $context, array $payload): array;
}

