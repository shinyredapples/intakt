<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TestDurations;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TestDurations::class)]
final class TestDurationsUnreadableRecordTest extends SandboxTestCase
{
    public function testARecordThatCannotBeReadIsIgnoredWithoutAWarning(): void
    {
        $this->skipWhenPermissionsAreIgnored();
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/durations.json', '{"tests/LongTest.php": 12.5}');
        chmod($sandbox->root.'/tests/durations.json', 0o000);
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $recorded = new TestDurations($sandbox->root)->recorded($sandbox->root);
        } finally {
            restore_error_handler();
            chmod($sandbox->root.'/tests/durations.json', 0o600);
        }

        self::assertSame([], $recorded);
        self::assertSame([], $warnings);
    }

    public function testARecordThatCanBeReadIsStillUsed(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/durations.json', '{"tests/LongTest.php": 12.5}');
        chmod($sandbox->root.'/tests/durations.json', 0o400);

        self::assertSame(['tests/LongTest.php' => 12.5], new TestDurations($sandbox->root)->recorded($sandbox->root));
    }
}
