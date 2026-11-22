<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\AdmitHeads;

use DateTimeImmutable;
use Intakt\Core\Watcher\Domain\AdmissionDecision;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\UseCase\AdmitHeads\AdmitHeads;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CiRun;
use Intakt\Plugin\Forge\Contract\CiRunOrigin;
use Intakt\Plugin\Forge\Contract\CiState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AdmitHeads::class)]
#[UsesClass(AdmissionDecision::class)]
#[UsesClass(HeadDecision::class)]
#[UsesClass(CiState::class)]
#[UsesClass(CiRun::class)]
#[UsesClass(CiRunOrigin::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(OpenChange::class)]
#[UsesClass(ChangeId::class)]
final class AdmitHeadsTest extends TestCase
{
    private RecordedChecks $forge;
    private RecordingLogger $log;
    private AdmitHeads $admit;

    public function testANewHeadWithPendingCiStartsNoRoundAndIsReadAgainAfterTheInterval(): void
    {
        $decision = ($this->admit)('demo', $this->change('1', 'a'));

        self::assertSame(HeadAdmission::Pending, $decision->admission);
        self::assertFalse($decision->startsRound());
        self::assertEquals(new DateTimeImmutable('2026-11-02T10:01:00+00:00'), $decision->readAgainAt);
    }

    public function testAGreenHeadIsAdmittedWithoutAReadAgainMoment(): void
    {
        $decision = ($this->admit)('demo', $this->change('2', 'b'));

        self::assertTrue($decision->startsRound());
        self::assertNull($decision->readAgainAt);
    }

    public function testRedCiIsRecordedInTheLogAndNotAdmitted(): void
    {
        $decision = ($this->admit)('demo', $this->change('3', 'c'));

        self::assertSame(HeadAdmission::Red, $decision->admission);
        self::assertFalse($decision->startsRound());
        self::assertSame('red', $this->log->logged[0][1]['admission']);
        self::assertSame(str_repeat('c', 40), $this->log->logged[0][1]['head']);
    }

    public function testACancelledRunOnTheHeadIsWaitedOnNotRecordedAsRed(): void
    {
        $decision = ($this->admit)('demo', $this->change('4', 'd'));

        self::assertSame(HeadAdmission::Pending, $decision->admission);
        self::assertEquals(new DateTimeImmutable('2026-11-02T10:01:00+00:00'), $decision->readAgainAt);
    }

    public function testACancelledRunReplacedByARerunOnTheSameHeadIsIgnored(): void
    {
        $decision = ($this->admit)('demo', $this->change('5', 'e'));

        self::assertTrue($decision->startsRound());
    }

    public function testACancelledRunNewerThanTheSucceededOneOnTheHeadIsWaitedOn(): void
    {
        $decision = ($this->admit)('demo', $this->change('6', 'f'));

        self::assertSame(HeadAdmission::Pending, $decision->admission);
        self::assertFalse($decision->startsRound());
    }

    public function testAFailedCheckSuiteOfAnotherAppMakesAHeadWithGreenActionsRed(): void
    {
        // Shaped like the head of #1061, because it merged while Dependabot's config check failed next to green Actions runs.
        $decision = ($this->admit)('demo', $this->change('1061', '1'));

        self::assertSame(HeadAdmission::Red, $decision->admission);
        self::assertFalse($decision->startsRound());
    }

    public function testTheCiStateIsReadForTheExactHeadAndOlderRunsDoNotPaintItGreen(): void
    {
        // The green recording holds a failed run of an older head, because a rerun must not decide for the new one.
        $decisions = [($this->admit)('demo', $this->change('1', 'a')), ($this->admit)('demo', $this->change('2', 'b'))];

        self::assertSame([str_repeat('a', 40), str_repeat('b', 40)], $this->forge->asked);
        self::assertSame([HeadAdmission::Pending, HeadAdmission::Green], array_map(static fn (HeadDecision $d): HeadAdmission => $d->admission, $decisions));
    }

    public function testAnAnswerForAnotherHeadIsAFailedRead(): void
    {
        $forge = new WrongHeadChecks();

        $this->expectException(ForgeReadFailed::class);

        (new AdmitHeads($forge, new AdmissionDecision(), new MockClock(), $this->log))('demo', $this->change('1', 'a'));
    }

    protected function setUp(): void
    {
        $this->forge = new RecordedChecks([str_repeat('a', 40) => 'pending', str_repeat('b', 40) => 'green', str_repeat('c', 40) => 'red', str_repeat('d', 40) => 'cancelled', str_repeat('e', 40) => 'rerun', str_repeat('f', 40) => 'cancelled-after-success', str_repeat('1', 40) => 'failed-dependabot-suite']);
        $this->log = new RecordingLogger();
        $this->admit = new AdmitHeads($this->forge, new AdmissionDecision(), new MockClock('2026-11-02T10:00:00+00:00'), $this->log);
    }

    private function change(string $id, string $head): OpenChange
    {
        return new OpenChange(new ChangeId($id), new CommitId(str_repeat($head, 40)), new DateTimeImmutable('2026-11-02T09:00:00+00:00'));
    }
}
