<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\Domain;

use DateTimeImmutable;
use Intakt\Core\Watcher\Domain\AdmissionDecision;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CiRun;
use Intakt\Plugin\Forge\Contract\CiRunOrigin;
use Intakt\Plugin\Forge\Contract\CiRunState;
use Intakt\Plugin\Forge\Contract\CiState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\OpenChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdmissionDecision::class)]
#[CoversClass(HeadDecision::class)]
#[UsesClass(CiState::class)]
#[UsesClass(CiRun::class)]
#[UsesClass(CiRunOrigin::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(OpenChange::class)]
#[UsesClass(ChangeId::class)]
final class AdmissionDecisionTest extends TestCase
{
    public function testAHeadWithARunStillGoingIsPending(): void
    {
        self::assertSame(HeadAdmission::Pending, $this->admission(CiRunState::Succeeded, CiRunState::Unfinished));
    }

    public function testAHeadWithoutRunsYetIsPending(): void
    {
        self::assertSame(HeadAdmission::Pending, $this->admission());
    }

    public function testAHeadWhoseRunsAllSucceededIsGreen(): void
    {
        self::assertSame(HeadAdmission::Green, $this->admission(CiRunState::Succeeded, CiRunState::Succeeded));
    }

    public function testAFailedRunMakesTheHeadRedEvenWhileOthersStillGo(): void
    {
        self::assertSame(HeadAdmission::Red, $this->admission(CiRunState::Unfinished, CiRunState::Failed));
    }

    public function testACancelledRunNothingReplacedIsPendingNotRed(): void
    {
        self::assertSame(HeadAdmission::Pending, $this->admission(CiRunState::Cancelled));
    }

    public function testACancelledRunIsIgnoredWhenANewerRunOfTheSameOriginReplacedIt(): void
    {
        $runs = [$this->ciRun(CiRunState::Cancelled, '10:00'), $this->ciRun(CiRunState::Succeeded, '10:05'), $this->ciRun(CiRunState::Succeeded, '10:00', new CiRunOrigin('lint', 'pull_request', new ChangeId('7')))];

        self::assertSame(HeadAdmission::Green, $this->admissionOfRuns(...$runs));
    }

    public function testACancelledRunNewerThanTheSucceededOneIsPending(): void
    {
        self::assertSame(HeadAdmission::Pending, $this->admissionOfRuns($this->ciRun(CiRunState::Cancelled, '10:05'), $this->ciRun(CiRunState::Succeeded, '10:00')));
    }

    public function testACancelledRunOfAnotherNameIsNotReplaced(): void
    {
        $lint = new CiRunOrigin('lint', 'pull_request', new ChangeId('7'));

        self::assertSame(HeadAdmission::Pending, $this->admissionOfRuns($this->ciRun(CiRunState::Cancelled, '10:00'), $this->ciRun(CiRunState::Succeeded, '10:05', $lint)));
    }

    public function testACancelledRunIsNotReplacedByANewerRunOfAnotherEvent(): void
    {
        $push = new CiRunOrigin('ci', 'push', new ChangeId('7'));

        self::assertSame(HeadAdmission::Pending, $this->admissionOfRuns($this->ciRun(CiRunState::Cancelled, '10:00'), $this->ciRun(CiRunState::Succeeded, '10:05', $push)));
    }

    public function testACancelledRunIsNotReplacedByANewerRunForAnotherPullRequest(): void
    {
        $other = new CiRunOrigin('ci', 'pull_request', new ChangeId('8'));

        self::assertSame(HeadAdmission::Pending, $this->admissionOfRuns($this->ciRun(CiRunState::Cancelled, '10:00'), $this->ciRun(CiRunState::Succeeded, '10:05', $other)));
    }

    public function testOnlyAGreenHeadStartsARound(): void
    {
        self::assertTrue($this->decided('1', HeadAdmission::Green)->startsRound());
        self::assertFalse($this->decided('1', HeadAdmission::Red)->startsRound());
        self::assertFalse($this->decided('1', HeadAdmission::Pending)->startsRound());
    }

    public function testHeadsWithFinishedCiComeBeforeHeadsWhoseCiRuns(): void
    {
        $ordered = new AdmissionDecision()->order([
            $this->decided('1', HeadAdmission::Pending),
            $this->decided('2', HeadAdmission::Green),
            $this->decided('3', HeadAdmission::Red),
        ]);

        self::assertSame(['2', '3', '1'], $this->ids($ordered));
    }

    public function testRunningHeadsKeepTheirPlaceAmongThemselves(): void
    {
        $ordered = new AdmissionDecision()->order([
            $this->decided('1', HeadAdmission::Pending),
            $this->decided('2', HeadAdmission::Pending),
            $this->decided('3', HeadAdmission::Green),
            $this->decided('4', HeadAdmission::Pending),
        ]);

        self::assertSame(['3', '1', '2', '4'], $this->ids($ordered));
    }

    private function admission(CiRunState ...$states): HeadAdmission
    {
        return $this->admissionOfRuns(...array_map(fn (CiRunState $state): CiRun => $this->ciRun($state, '10:00'), $states));
    }

    private function admissionOfRuns(CiRun ...$runs): HeadAdmission
    {
        return new AdmissionDecision()->admissionOf(new CiState(new CommitId(str_repeat('a', 40)), array_values($runs)));
    }

    private function ciRun(CiRunState $state, string $startedAt, ?CiRunOrigin $origin = null): CiRun
    {
        return new CiRun($origin ?? new CiRunOrigin('ci', 'pull_request', new ChangeId('7')), $state, new DateTimeImmutable('2026-11-02T'.$startedAt.':00+00:00'));
    }

    private function decided(string $id, HeadAdmission $admission): HeadDecision
    {
        return new HeadDecision(new OpenChange(new ChangeId($id), new CommitId(str_repeat('a', 40)), new DateTimeImmutable('2026-11-02T10:00:00+00:00')), $admission);
    }

    /**
     * @param list<HeadDecision> $decisions
     *
     * @return list<string>
     */
    private function ids(array $decisions): array
    {
        return array_map(static fn (HeadDecision $decision): string => $decision->change->id->value, $decisions);
    }
}
