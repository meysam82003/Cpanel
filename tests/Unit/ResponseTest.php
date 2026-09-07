<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testDownloadBuildsSafeHeaders(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tcm-response-');
        self::assertIsString($path);
        file_put_contents($path, 'abc');
        try {
            $response = Response::download($path, "گزارش\r\nInjected.txt", "text/plain\r\nX-Evil: yes");
            self::assertSame('3', $response->headers['Content-Length']);
            self::assertSame('application/octet-stream', $response->headers['Content-Type']);
            self::assertStringNotContainsString("\r", $response->headers['Content-Disposition']);
            self::assertStringNotContainsString("\n", $response->headers['Content-Disposition']);
        } finally {
            @unlink($path);
        }
    }

    public function testDownloadRejectsMissingSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::download(sys_get_temp_dir() . '/missing-tcm-file', 'x.txt', 'text/plain');
    }
}
