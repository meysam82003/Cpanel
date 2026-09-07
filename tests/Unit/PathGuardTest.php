<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppException;
use App\Security\PathGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase
{
    private PathGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new PathGuard();
    }

    public function testNormalizesRelativeAndAbsolutePathsWithinTenantRoot(): void
    {
        self::assertSame('/home/alice/public_html/index.php', $this->guard->normalize('public_html/./assets/../index.php', '/home/alice'));
        self::assertSame('/home/alice', $this->guard->normalize('/home/alice/', '/home/alice'));
    }

    #[DataProvider('traversalCases')]
    public function testBlocksTraversalAndAmbiguousEncoding(string $path): void
    {
        try {
            $this->guard->normalize($path, '/home/alice');
            self::fail('Traversal input was accepted: ' . $path);
        } catch (AppException $exception) {
            self::assertContains($exception->safeCode, ['path_outside_root', 'path_traversal_blocked']);
        }
    }

    public static function traversalCases(): array
    {
        return [
            ['../bob/secret'],
            ['/etc/passwd'],
            ['public_html/%2e%2e/.env'],
            ['public_html/%252e%252e/secret'],
            ["public_html/a\0b"],
            ['..\\..\\etc\\passwd'],
        ];
    }

    public function testSanitizesFilenameWithoutAllowingDirectorySegments(): void
    {
        self::assertSame('index.php', $this->guard->sanitizeFilename('/index.php'));
        $this->expectException(AppException::class);
        $this->guard->sanitizeFilename('..');
    }
}
