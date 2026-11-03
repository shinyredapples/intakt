<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateResult;
use Intakt\Gates\TestDurations;
use Intakt\Gates\TestsGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(TestsGate::class)]
#[UsesClass(TestDurations::class)]
#[UsesClass(GateResult::class)]
final class TestsGateDurationsTest extends SandboxTestCase
{
    public function testAPassingRunWritesOneDurationPerTestFileIntoTheGatesCache(): void
    {
        $cache = $this->emptySandbox();

        $run = $this->sandboxWith('tests-clean')->checkWith(['INTAKT_GATES_CACHE' => $cache->root], '--only=tests');

        self::assertTrue($run->passed('tests'), $run->output());
        $written = json_decode((string) file_get_contents($cache->root.'/tests/durations.json'), true);
        self::assertIsArray($written);
        self::assertEqualsCanonicalizing(['tests/SampleTest.php', 'tests/Gates/GateTest.php'], array_keys($written));
    }

    public function testTheSlowestFilesAreOnTheGateLineExactlyOnceWhenBothPassesRun(): void
    {
        $cache = $this->emptySandbox();

        $run = $this->sandboxWith('tests-clean')->checkWith(['INTAKT_GATES_CACHE' => $cache->root], '--only=tests');

        self::assertEqualsCanonicalizing(['tests/SampleTest.php', 'tests/Gates/GateTest.php'], $run->slowestFilesOf('tests'));
        self::assertSame(1, substr_count($run->output(), '"slowest"'), $run->output());
    }

    public function testAFailingTestStillFailsTheGateWhileDurationsAreRecorded(): void
    {
        $cache = $this->emptySandbox();

        $run = $this->sandboxWith('tests-mock-without-expectations')->checkWith(['INTAKT_GATES_CACHE' => $cache->root], '--only=tests');

        self::assertTrue($run->failed('tests'), $run->output());
        self::assertStringContainsString('No expectations were configured for the mock object', $run->findingsOf('tests'));
        self::assertFileExists($cache->root.'/tests/durations.json');
    }
}
