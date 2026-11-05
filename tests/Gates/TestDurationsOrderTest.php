<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TestDurations;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TestDurations::class)]
final class TestDurationsOrderTest extends SandboxTestCase
{
    public function testTheQueueStartsWithTheLongestRecordedFile(): void
    {
        $durations = new TestDurations($this->emptySandbox()->root);

        $queue = $durations->longestFirst(['tests/ShortTest.php', 'tests/LongTest.php', 'tests/MiddleTest.php'], ['tests/ShortTest.php' => 0.5, 'tests/LongTest.php' => 12.0, 'tests/MiddleTest.php' => 3.0]);

        self::assertSame(['tests/LongTest.php', 'tests/MiddleTest.php', 'tests/ShortTest.php'], $queue);
    }

    public function testFilesWithoutARecordedDurationGoFirstTheLargestOfThemFirst(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/SmallNewTest.php', '<?php');
        $sandbox->write('tests/LargeNewTest.php', str_repeat('<?php ', 100));

        $queue = new TestDurations($sandbox->root)->longestFirst(['tests/SmallNewTest.php', 'tests/KnownTest.php', 'tests/LargeNewTest.php'], ['tests/KnownTest.php' => 60.0]);

        self::assertSame(['tests/LargeNewTest.php', 'tests/SmallNewTest.php', 'tests/KnownTest.php'], $queue);
    }

    public function testTheRecordOfAnEarlierRunIsReadBack(): void
    {
        $cache = $this->emptySandbox()->root;
        $durations = new TestDurations($cache);

        $durations->write(['tests/LongTest.php' => 12.5, 'tests/ShortTest.php' => 1.0], $cache);

        self::assertSame(['tests/LongTest.php' => 12.5, 'tests/ShortTest.php' => 1.0], $durations->recorded($cache));
    }

    public function testWithoutARecordEveryFileCountsAsUnrecorded(): void
    {
        $cache = $this->emptySandbox()->root;

        self::assertSame([], new TestDurations($cache)->recorded($cache));
    }

    public function testARecordThatIsNoMapOfSecondsIsIgnored(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/durations.json', '{"tests/LongTest.php": "slow", "tests/ShortTest.php": 1, "nested": {"a": 1}}');

        self::assertSame([], new TestDurations($sandbox->root)->recorded($sandbox->root));
    }

    public function testAnEntryThatIsNoNumberOfSecondsIsLeftOut(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/durations.json', '{"tests/LongTest.php": "slow", "tests/ShortTest.php": 1}');

        self::assertSame(['tests/ShortTest.php' => 1.0], new TestDurations($sandbox->root)->recorded($sandbox->root));
    }
}
