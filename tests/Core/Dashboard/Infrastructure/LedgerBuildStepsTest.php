<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Infrastructure\LedgerBuildSteps;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LedgerBuildSteps::class)]
#[UsesClass(LedgerPayload::class)]
final class LedgerBuildStepsTest extends TestCase
{
    private const string OLDER = '019a8f00-0000-7000-8000-000000000001';

    private const string NEWER = '019a8f00-0000-7000-8000-000000000002';

    public function testTheRunWithTheGreaterIdIsTheLatestThoughItsFirstRowCameFirst(): void
    {
        $steps = $this->latestRunOf([
            ['run' => self::NEWER, 'step' => 'task'],
            ['run' => self::NEWER, 'step' => 'mirror', 'outcome' => 'failed', 'failure' => 'fetch refused'],
            ['run' => self::OLDER, 'step' => 'task'],
            ['run' => self::OLDER, 'step' => 'mirror'],
        ]);

        self::assertSame([['task', 'passed'], ['mirror', 'failed']], $this->stepsAndOutcomes($steps));
        self::assertSame('fetch refused', $steps[1]['failure']);
    }

    public function testARowWhoseRunIsNullIsLeftOutAndDoesNotReplaceTheNamedRun(): void
    {
        $steps = $this->latestRunOf([
            ['run' => self::OLDER, 'step' => 'task'],
            ['run' => null, 'step' => 'mirror', 'outcome' => 'failed', 'failure' => 'invalid row'],
        ]);

        self::assertSame([['task', 'passed']], $this->stepsAndOutcomes($steps));
    }

    public function testAnOlderRowAfterAFailedStepStartsANewRunThoughItsTaskRowWasMalformed(): void
    {
        $steps = $this->latestRunOf([
            ['step' => 'task', 'outcome' => 'failed', 'failure' => 'old issue read failed'],
            ['step' => 'task', 'outcome' => 'malformed'],
            ['step' => 'mirror'],
        ]);

        self::assertSame([['mirror', 'passed']], $this->stepsAndOutcomes($steps));
    }

    public function testAMalformedTaskRowOfTheTaskEndsTheOlderRunBeforeIt(): void
    {
        $steps = $this->latestRunOf([
            ['step' => 'task'],
            ['step' => 'task', 'outcome' => 'malformed'],
            ['step' => 'mirror'],
        ]);

        self::assertSame([['mirror', 'passed']], $this->stepsAndOutcomes($steps));
    }

    public function testAMalformedTaskRowOfAnotherTaskLeavesTheOlderRunWhole(): void
    {
        $steps = $this->latestRunOf([
            ['step' => 'task'],
            ['task' => '43', 'step' => 'task', 'outcome' => 'malformed'],
            ['step' => 'mirror'],
        ]);

        self::assertSame([['task', 'passed'], ['mirror', 'passed']], $this->stepsAndOutcomes($steps));
    }

    public function testAnOlderRowOfTheNextStepAfterAPassedStepContinuesItsRun(): void
    {
        $steps = $this->latestRunOf([
            ['step' => 'task'],
            ['step' => 'mirror'],
            ['step' => 'clone', 'outcome' => 'failed', 'failure' => 'the folder exists already'],
        ]);

        self::assertSame([['task', 'passed'], ['mirror', 'passed'], ['clone', 'failed']], $this->stepsAndOutcomes($steps));
    }

    public function testReadingFourTimesAsManyOlderRowsTakesAboutFourTimesAsLong(): void
    {
        $small = $this->fastestReadOf(4000);
        $large = $this->fastestReadOf(16000);

        self::assertLessThan(8 * $small, $large, sprintf('4000 rows took %.3f s, 16000 rows %.3f s', $small, $large));
    }

    private function fastestReadOf(int $rows): float
    {
        $events = new RecordedEvents(array_map(fn (): RecordedEvent => $this->row(['step' => 'task']), range(1, $rows)));
        $fastest = INF;

        foreach (range(1, 3) as $ignored) {
            $start = hrtime(true);
            (void) new LedgerBuildSteps()->latestRunOf($events, 'sample', '42');
            $fastest = min($fastest, (hrtime(true) - $start) / 1e9);
        }

        return $fastest;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}>
     */
    private function latestRunOf(array $rows): array
    {
        return new LedgerBuildSteps()->latestRunOf(new RecordedEvents(array_map($this->row(...), $rows)), 'sample', '42');
    }

    /**
     * @param list<array{step: string, outcome: string}> $steps
     *
     * @return list<array{string, string}>
     */
    private function stepsAndOutcomes(array $steps): array
    {
        return array_map(static fn (array $step): array => [$step['step'], $step['outcome']], $steps);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function row(array $data): RecordedEvent
    {
        $payload = json_encode($data + ['project' => 'sample', 'task' => '42', 'outcome' => 'passed', 'failure' => null], JSON_THROW_ON_ERROR);

        return new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::BuildStepEnded, $payload), new DateTimeImmutable('2026-11-22T00:00:00+00:00'));
    }
}
