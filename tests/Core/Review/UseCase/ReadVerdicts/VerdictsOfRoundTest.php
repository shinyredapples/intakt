<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReadVerdicts;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Contract\IdentityField;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Core\Review\Contract\VerdictStanding;
use Intakt\Core\Review\Domain\ReplayProject;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\MarkReplay\FlagProjectAsReplayHandler;
use Intakt\Core\Review\UseCase\ReadVerdicts\RoundNotFound;
use Intakt\Core\Review\UseCase\ReadVerdicts\VerdictsOfRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\IdentityNotOfRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

#[Group('persistence')]
#[CoversClass(VerdictsOfRoundHandler::class)]
#[CoversClass(VerdictsOfRound::class)]
#[CoversClass(RoundVerdicts::class)]
#[CoversClass(RoundNotFound::class)]
#[CoversClass(IdentityNotOfRound::class)]
#[CoversClass(ReviewRound::class)]
#[CoversClass(ReviewRounds::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(VerdictStanding::class)]
#[UsesClass(ReviewTerms::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(Paths::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(HeadMove::class)]
#[UsesClass(FlagProjectAsReplayHandler::class)]
#[UsesClass(FlagProjectAsReplay::class)]
#[UsesClass(ReplayProject::class)]
#[UsesClass(ReplayMark::class)]
final class VerdictsOfRoundTest extends KernelTestCase
{
    private RoundFixture $fixture;

    public function testTheVerdictsOfARoundOnAnUnchangedIdentityAreCurrentAndCount(): void
    {
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, new Paths())));

        self::assertSame(Standing::Current, $verdicts->standing->standing);
        self::assertSame([Decision::Approve, Decision::Approve], array_map(static fn (PanelVerdict $verdict): Decision => $verdict->verdict->verdict, $verdicts->counting));
    }

    public function testANewCommitOnTheHeadMakesTheStoredVerdictsStaleSoNoneCounts(): void
    {
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::NEW_HEAD, 2), new HeadMove(HeadMoveKind::Commits, new Paths('src/Feature.php'))));

        self::assertSame(Standing::Stale, $verdicts->standing->standing);
        self::assertSame([IdentityField::Head], $verdicts->standing->changed);
        self::assertSame([], $verdicts->counting);
    }

    public function testADocsOnlyCommitKeepsTheStoredVerdictsForTheUntouchedParts(): void
    {
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::NEW_HEAD, 2), new HeadMove(HeadMoveKind::Commits, new Paths('docs/feature.md'))));

        self::assertSame(Standing::KeptForUntouchedParts, $verdicts->standing->standing);
        self::assertSame(['docs/feature.md'], [...$verdicts->standing->reviewAgain]);
        self::assertCount(2, $verdicts->counting);
    }

    public function testARoundStoredWithoutAnIdentityIsStaleInEveryField(): void
    {
        $round = $this->reviewedRound();
        $this->fixture->entities->getConnection()->executeStatement("UPDATE review_round SET verdict_identity = ''");

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, new Paths())));

        self::assertSame(Standing::Stale, $verdicts->standing->standing);
        self::assertSame(IdentityField::cases(), $verdicts->standing->changed);
        self::assertSame([], $verdicts->counting);
    }

    public function testTheVerdictsOfAReviewOfAReplayProjectNeverCount(): void
    {
        $this->flag('alpha');
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, new Paths())));

        self::assertSame(Standing::Current, $verdicts->standing->standing);
        self::assertSame([], $verdicts->counting);
    }

    public function testTheVerdictsOfAReviewOfALiveProjectCountBesideAReplayProject(): void
    {
        $this->flag('beta');
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, new Paths())));

        self::assertCount(2, $verdicts->counting);
    }

    public function testAnUnknownRoundIsRefused(): void
    {
        $this->expectException(RoundNotFound::class);

        $this->fixture->verdictsOf(new VerdictsOfRound('not-a-round', $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, new Paths())));
    }

    public function testARoundIsStoredWithAnIdentityOfItsOwnTaskAndPanel(): void
    {
        $round = $this->fixture->startWithIdentityOf(RoundFixture::TASK, 2, 2);

        self::assertCount(1, $this->fixture->storedRounds());
        self::assertSame(Standing::Current, $this->fixture->round($round)->standingAt($this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, new Paths()))->standing);
    }

    public function testARoundWhoseIdentityNamesAnotherTaskIsRefused(): void
    {
        $this->expectException(IdentityNotOfRound::class);

        $this->fixture->startWithIdentityOf('Add another thing.', 2, 2);
    }

    public function testARoundWhoseIdentityNamesAnotherPanelIsRefused(): void
    {
        $this->expectException(IdentityNotOfRound::class);

        $this->fixture->startWithIdentityOf(RoundFixture::TASK, 3, 2);
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function reviewedRound(): string
    {
        $round = $this->fixture->start(2);
        $vendor = new VerdictPerRole();

        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $round->id()->toRfc4122();
    }

    private function flag(string $project): void
    {
        new FlagProjectAsReplayHandler($this->fixture->entities)(new FlagProjectAsReplay($project));
    }
}
