<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Dashboard\Infrastructure\LatestBuildRun;
use Intakt\Core\Dashboard\Infrastructure\LedgerBuildSteps;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LedgerBuildSteps::class)]
#[CoversClass(LatestBuildRun::class)]
#[UsesClass(PayloadFields::class)]
final class LedgerBuildStepsTest extends TestCase
{
    // The newer run's id sorts first, as it can with a random id or a clock set back, so only the order of the rows tells the runs apart.
    private const string OLDER = 'f1d0e2c4-5b6a-4c8d-9e0f-000000000002';

    private const string NEWER = 'f1d0e2c4-5b6a-4c8d-9e0f-000000000001';

    public function testTheRunWhoseFirstRowComesLaterIsTheLatestThoughItsIdSortsFirst(): void
    {
        $steps = $this->latestRunOf([
            ['run' => self::OLDER, 'step' => 'task'],
            ['run' => self::NEWER, 'step' => 'task'],
            ['run' => self::NEWER, 'step' => 'mirror', 'outcome' => 'failed', 'failure' => 'fetch refused'],
            ['run' => self::OLDER, 'step' => 'mirror'],
        ]);

        self::assertSame([['task', 'passed'], ['mirror', 'failed']], $this->stepsAndOutcomes($steps));
        self::assertSame('fetch refused', $steps[1]['failure']);
    }

    public function testTheLaneShowsTheNewerRunsStepThoughTheOlderRunWritesLast(): void
    {
        $steps = $this->in([
            ['run' => self::OLDER, 'step' => 'task'],
            ['run' => self::NEWER, 'step' => 'task'],
            ['run' => self::NEWER, 'step' => 'build_job', 'outcome' => 'asked'],
            ['run' => self::OLDER, 'step' => 'build_job', 'outcome' => 'asked'],
        ]);

        self::assertSame([['build_job', self::NEWER]], array_map(static fn (array $step): array => [$step['step'], $step['run']], $steps));
    }

    public function testAStepAnOlderRunWritesLastDoesNotMoveItsTaskToTheFrontOfTheLane(): void
    {
        $steps = $this->in([
            ['run' => self::OLDER, 'step' => 'task'],
            ['run' => self::NEWER, 'step' => 'task'],
            ['task' => '43', 'step' => 'task'],
            ['run' => self::OLDER, 'step' => 'mirror'],
        ]);

        self::assertSame(['43', '42'], array_column($steps, 'task'));
    }

    public function testAMalformedFirstRowOfANamedRunStillFixesWhereThatRunStarts(): void
    {
        $steps = $this->latestRunOf([
            ['run' => self::OLDER, 'step' => 'task', 'outcome' => 'malformed'],
            ['run' => self::NEWER, 'step' => 'task'],
            ['run' => self::NEWER, 'step' => 'mirror', 'outcome' => 'failed', 'failure' => 'fetch refused'],
            ['run' => self::OLDER, 'step' => 'mirror'],
        ]);

        self::assertSame([['task', 'passed'], ['mirror', 'failed']], $this->stepsAndOutcomes($steps));
    }

    public function testAMalformedRowOfANamedRunOfAnotherTaskDoesNotStartARunOfThisTask(): void
    {
        $steps = $this->latestRunOf([
            ['run' => self::OLDER, 'step' => 'task'],
            ['run' => self::NEWER, 'task' => '43', 'step' => 'task', 'outcome' => 'malformed'],
        ]);

        self::assertSame([['task', 'passed']], $this->stepsAndOutcomes($steps));
    }

    public function testTheUnnamedRunNeverHoldsMoreStepsThanTheBuildHasAfterManyLegacyRows(): void
    {
        $steps = array_column(BuildStep::cases(), 'value');
        $rows = array_map(static fn (int $index): array => ['step' => $steps[$index * 5 % count($steps)]], range(0, 2000));
        $lastRun = array_map(static fn (string $step): array => ['step' => $step], $steps);

        $latest = $this->latestRunOf([...$rows, ...$lastRun]);

        self::assertLessThanOrEqual(count($steps), count($latest));
        self::assertSame(array_map(static fn (string $step): array => [$step, 'passed'], $steps), $this->stepsAndOutcomes($latest));
    }

    public function testRowsWithoutARunAfterANamedRunAreTheLatestRunAsAfterARollback(): void
    {
        $steps = $this->latestRunOf([
            ['run' => self::OLDER, 'step' => 'task'],
            ['run' => self::OLDER, 'step' => 'mirror'],
            ['step' => 'task'],
            ['step' => 'mirror', 'outcome' => 'failed', 'failure' => 'mirror refused'],
        ]);

        self::assertSame([['task', 'passed'], ['mirror', 'failed']], $this->stepsAndOutcomes($steps));
    }

    public function testANamedRunAfterRowsWithoutARunIsTheLatestRun(): void
    {
        $steps = $this->latestRunOf([
            ['step' => 'task'],
            ['step' => 'mirror', 'outcome' => 'failed', 'failure' => 'mirror refused'],
            ['run' => self::OLDER, 'step' => 'task'],
        ]);

        self::assertSame([['task', 'passed']], $this->stepsAndOutcomes($steps));
    }

    public function testARowWhoseRunIsNoUuidIsLeftOutSoItCannotHideALaterRun(): void
    {
        $steps = $this->latestRunOf([
            ['run' => 'ffffffffffffffffffffffffffffffff', 'step' => 'task'],
            ['run' => self::OLDER, 'step' => 'task'],
            ['run' => self::OLDER, 'step' => 'mirror', 'outcome' => 'failed', 'failure' => 'mirror refused'],
            ['run' => 'ffffffffffffffffffffffffffffffff', 'step' => 'mirror'],
            ['run' => 'run-1', 'step' => 'mirror'],
        ]);

        self::assertSame([['task', 'passed'], ['mirror', 'failed']], $this->stepsAndOutcomes($steps));
    }

    public function testARunIdInUpperCaseIsTheSameRunAsInLowerCase(): void
    {
        $steps = $this->latestRunOf([
            ['run' => self::OLDER, 'step' => 'task'],
            ['run' => strtoupper(self::OLDER), 'step' => 'mirror'],
        ]);

        self::assertSame([['task', 'passed'], ['mirror', 'passed']], $this->stepsAndOutcomes($steps));
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

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string}>
     */
    private function latestRunOf(array $rows): array
    {
        return new LedgerBuildSteps()->latestRunOf(new RecordedEvents(array_map($this->row(...), $rows)), 'sample', '42');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string}>
     */
    private function in(array $rows): array
    {
        return new LedgerBuildSteps()->in(new RecordedEvents(array_map($this->row(...), $rows)));
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
