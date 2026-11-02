<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReadVerdicts;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\IdentityField;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictStanding;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\RoundNotFound;
use Intakt\Core\Review\UseCase\ReadVerdicts\ShadowRounds;
use Intakt\Core\Review\UseCase\ReadVerdicts\VerdictsOfRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\VerdictsOfRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\IdentityNotOfRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Shadow\Infrastructure\ConfiguredProjectModes;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\ScriptedVendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('persistence')]
#[CoversClass(VerdictsOfRoundHandler::class)]
#[CoversClass(VerdictsOfRound::class)]
#[CoversClass(ShadowRounds::class)]
#[UsesClass(ConfiguredProjectModes::class)]
#[CoversClass(RoundVerdicts::class)]
#[CoversClass(RoundNotFound::class)]
#[CoversClass(IdentityNotOfRound::class)]
#[CoversClass(ReviewRound::class)]
#[CoversClass(ReviewRounds::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(VerdictStanding::class)]
#[UsesClass(ReviewTerms::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(HeadMove::class)]
final class VerdictsOfRoundTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testTheVerdictsOfARoundOnAnUnchangedIdentityAreCurrentAndCount(): void
    {
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, [])));

        self::assertSame(Standing::Current, $verdicts->standing->standing);
        self::assertSame([Decision::Approve, Decision::Approve], array_map(static fn (PanelVerdict $verdict): Decision => $verdict->verdict->verdict, $verdicts->counting));
    }

    public function testAShadowProjectsCurrentVerdictsDoNotCountForAMerge(): void
    {
        $this->fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes(['alpha' => 'shadow']));
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsOf($this->unmovedQuery($round));

        self::assertSame(Standing::Current, $verdicts->standing->standing);
        self::assertSame([], $verdicts->counting);
    }

    public function testVerdictsRecordedAsShadowStillDoNotCountOnceTheProjectIsLive(): void
    {
        $this->fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes(['alpha' => 'shadow']));
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsReadWith(new ConfiguredProjectModes(['alpha' => 'live']), $this->unmovedQuery($round));

        self::assertSame([], $verdicts->counting);
    }

    public function testVerdictsRecordedAsLiveDoNotCountOnceTheProjectIsShadow(): void
    {
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsReadWith(new ConfiguredProjectModes(['alpha' => 'shadow']), $this->unmovedQuery($round));

        self::assertSame([], $verdicts->counting);
    }

    public function testAnotherProjectInShadowModeLeavesTheVerdictsOfThisOneCounting(): void
    {
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsReadWith(new ConfiguredProjectModes(['beta' => 'shadow']), $this->unmovedQuery($round));

        self::assertCount(2, $verdicts->counting);
    }

    public function testANewCommitOnTheHeadMakesTheStoredVerdictsStaleSoNoneCounts(): void
    {
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::NEW_HEAD, 2), new HeadMove(HeadMoveKind::Commits, ['src/Feature.php'])));

        self::assertSame(Standing::Stale, $verdicts->standing->standing);
        self::assertSame([IdentityField::Head], $verdicts->standing->changed);
        self::assertSame([], $verdicts->counting);
    }

    public function testADocsOnlyCommitKeepsTheStoredVerdictsForTheUntouchedParts(): void
    {
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::NEW_HEAD, 2), new HeadMove(HeadMoveKind::Commits, ['docs/feature.md'])));

        self::assertSame(Standing::KeptForUntouchedParts, $verdicts->standing->standing);
        self::assertSame(['docs/feature.md'], $verdicts->standing->reviewAgain);
        self::assertCount(2, $verdicts->counting);
    }

    public function testAnotherPanelMakesTheStoredVerdictsStale(): void
    {
        $round = $this->reviewedRound();

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::HEAD, 3), new HeadMove(HeadMoveKind::Unmoved, [])));

        self::assertSame(Standing::Stale, $verdicts->standing->standing);
        self::assertSame([IdentityField::PanelPolicy], $verdicts->standing->changed);
    }

    public function testARoundStoredWithoutAnIdentityIsStaleInEveryField(): void
    {
        $round = $this->reviewedRound();
        $this->fixture->entities->getConnection()->executeStatement("UPDATE review_round SET verdict_identity = ''");

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, [])));

        self::assertSame(Standing::Stale, $verdicts->standing->standing);
        self::assertSame(IdentityField::cases(), $verdicts->standing->changed);
        self::assertSame([], $verdicts->counting);
    }

    public function testAnUnknownRoundIsRefused(): void
    {
        $this->expectException(RoundNotFound::class);

        $this->fixture->verdictsOf(new VerdictsOfRound('not-a-round', $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, [])));
    }

    public function testARoundIsStoredWithAnIdentityOfItsOwnTaskAndPanel(): void
    {
        $round = $this->fixture->startWithIdentityOf(RoundFixture::TASK, 2, 2);

        self::assertCount(1, $this->fixture->storedRounds());
        self::assertSame(Standing::Current, $this->fixture->round($round)->standingAt($this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, []))->standing);
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

    private function unmovedQuery(string $round): VerdictsOfRound
    {
        return new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, []));
    }

    private function reviewedRound(): string
    {
        $round = $this->fixture->start(2);
        $vendor = new ScriptedVendor();

        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $round->id()->toRfc4122();
    }
}
