<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewLane;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Domain\OwnerProposals;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\TrackedFinding;
use Intakt\Core\Review\Infrastructure\DocsPartOfDiff;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Support\KernelTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

#[Group('persistence')]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[UsesClass(DocsPartOfDiff::class)]
#[UsesClass(OwnerProposals::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
final class TechnicalWriterLaneTest extends KernelTestCase
{
    private const string OFF_THE_PAGES = VerdictUnreadable::IN_WORDS.': the Technical writer reported a finding outside the docs pages.';

    private const string REPEATED_PROPOSAL = VerdictUnreadable::IN_WORDS.': the Technical writer repeated an open finding as a proposal.';

    private RoundFixture $fixture;

    private int $delivered = 0;

    /** @var array<string, list<string>> */
    private array $openGiven = [];

    public function testTheTechnicalWriterIsGivenOnlyTheDocsPartOfTheDiffInTheDocsLane(): void
    {
        $requests = $this->deliverAll(new Verdict(Decision::Approve, [], [], []));

        self::assertSame([[ReviewLane::Code, RoundFixture::DIFF], [ReviewLane::Docs, '']], $requests);
    }

    public function testAProposalOfTheTechnicalWriterIsRecordedAsAnOwnersP3(): void
    {
        $proposal = new Finding('F1', Severity::P1, 'docs/user/README.md', 1, null, 'Split the chapter.', FixableBy::Builder, false, 'Proposal: user guide structure', null);

        $this->deliverAll(new Verdict(Decision::Changes, [$proposal], [], []));

        $stored = array_map(static fn ($tracked): array => [$tracked->latest->severity, $tracked->latest->fixable_by], $this->fixture->storedFindings(RoundFixture::CHANGE));
        self::assertContains([Severity::P3, FixableBy::Owner], $stored);
        self::assertNotContains([Severity::P1, FixableBy::Builder], $stored);
    }

    public function testTheTechnicalWriterIsGivenOnlyTheOpenFindingsOnPagesAndCannotCloseOneOnCode(): void
    {
        $this->deliverAll(new Verdict(Decision::Approve, [], [], []), new Verdict(Decision::Changes, [$this->blockingAt('src/Cart.php'), $this->blockingAt('docs/user/cart.md')], [], []));
        $this->startRound();

        $this->deliverAll(new Verdict(Decision::Approve, [], [new ResolvedFinding('F1')], []));

        self::assertSame([ReviewLane::Code->value => ['F1', 'F2'], ReviewLane::Docs->value => ['F2']], $this->openGiven);
        self::assertSame(['F1', 'F2'], $this->stillOpen());
    }

    public function testTheTechnicalWriterClosesAFindingOnAPage(): void
    {
        $this->deliverAll(new Verdict(Decision::Approve, [], [], []), new Verdict(Decision::Changes, [$this->blockingAt('src/Cart.php'), $this->blockingAt('docs/user/cart.md')], [], []));
        $this->startRound();

        $this->deliverAll(new Verdict(Decision::Approve, [], [new ResolvedFinding('F2')], []));

        self::assertSame(['F1'], $this->stillOpen());
    }

    public function testAFindingOfTheTechnicalWriterOnCodeStoresNothingAndFailsItsReviewAsUnreadable(): void
    {
        $this->deliverAll(new Verdict(Decision::Changes, [$this->blockingAt('src/Cart.php')], [], []));

        self::assertSame([], $this->fixture->storedFindings(RoundFixture::CHANGE));
        self::assertSame(self::OFF_THE_PAGES, $this->writersFailure());
    }

    public function testAFindingOfTheTechnicalWriterWithoutAFileStoresNothing(): void
    {
        $this->deliverAll(new Verdict(Decision::Changes, [new Finding('X', Severity::P1, null, null, null, 'Wrong.', FixableBy::Builder, false, 'SOLID', null)], [], []));

        self::assertSame([], $this->fixture->storedFindings(RoundFixture::CHANGE));
        self::assertSame(self::OFF_THE_PAGES, $this->writersFailure());
    }

    public function testAFindingOfTheTechnicalWriterOnAPathThatLeavesTheDocsStoresNothing(): void
    {
        $this->deliverAll(new Verdict(Decision::Changes, [$this->blockingAt('docs/../CONTRIBUTING.md')], [], []));

        self::assertSame([], $this->fixture->storedFindings(RoundFixture::CHANGE));
        self::assertSame(self::OFF_THE_PAGES, $this->writersFailure());
    }

    public function testAnOpenFindingOnAPathThatLeavesTheDocsIsNotShownToTheTechnicalWriter(): void
    {
        $this->deliverAll(new Verdict(Decision::Approve, [], [], []), new Verdict(Decision::Changes, [$this->blockingAt('docs/../CONTRIBUTING.md')], [], []));
        $this->startRound();

        $this->deliverAll(new Verdict(Decision::Approve, [], [], []));

        self::assertSame([ReviewLane::Code->value => ['F1'], ReviewLane::Docs->value => []], $this->openGiven);
    }

    /**
     * @return iterable<string, array{?string, FixableBy}>
     */
    public static function repeatsThatDropTheOwnerRouting(): iterable
    {
        yield 'no proposal marker, builder' => ['SOLID', FixableBy::Builder];
        yield 'no proposal marker, owner' => ['SOLID', FixableBy::Owner];
        yield 'no requirement, builder' => [null, FixableBy::Builder];
    }

    #[DataProvider('repeatsThatDropTheOwnerRouting')]
    public function testARepeatOfAnOpenOwnerProposalKeepsItsOwnerRoutingWhateverTheModelWrote(?string $requirement, FixableBy $fixableBy): void
    {
        $this->deliverAll(new Verdict(Decision::Changes, [new Finding('X', Severity::P1, 'docs/user/cart.md', 1, null, 'Split the chapter.', FixableBy::Builder, false, 'Proposal: user guide structure', null)], [], []));
        $this->startRound();

        $this->deliverAll(new Verdict(Decision::Changes, [new Finding('F1', Severity::P1, 'docs/user/cart.md', 1, null, 'Split the chapter now.', $fixableBy, true, $requirement, null)], [], []));

        self::assertNull($this->writersFailure());
        self::assertSame([[Severity::P3, FixableBy::Owner, true]], array_map(static fn (TrackedFinding $tracked): array => [$tracked->latest->severity, $tracked->latest->fixable_by, $tracked->latest->proposesToOwner()], $this->fixture->storedFindings(RoundFixture::CHANGE)));
    }

    public function testAFindingOfTheTechnicalWriterOnAPageIsStored(): void
    {
        $this->deliverAll(new Verdict(Decision::Changes, [$this->blockingAt('docs/user/cart.md')], [], []));

        self::assertCount(1, $this->fixture->storedFindings(RoundFixture::CHANGE));
        self::assertNull($this->writersFailure());
    }

    public function testAProposalOfTheTechnicalWriterThatRepeatsAnOpenFindingIsRefusedAsUnreadable(): void
    {
        $this->deliverAll(new Verdict(Decision::Approve, [], [], []), new Verdict(Decision::Changes, [$this->blockingAt('docs/user/cart.md')], [], []));
        $this->startRound();

        $this->deliverAll(new Verdict(Decision::Changes, [new Finding('F1', Severity::P1, 'docs/user/cart.md', 1, null, 'Split the chapter.', FixableBy::Builder, true, 'Proposal: user guide structure', null)], [], []));

        self::assertSame(self::REPEATED_PROPOSAL, $this->writersFailure());
    }

    public function testARepeatOfTheTechnicalWriterThatMovesAnOpenFindingOntoCodeIsRefusedAndLeavesTheFindingOnItsPage(): void
    {
        $this->deliverAll(new Verdict(Decision::Approve, [], [], []), new Verdict(Decision::Changes, [$this->blockingAt('docs/user/cart.md')], [], []));
        $this->startRound();

        $this->deliverAll(new Verdict(Decision::Changes, [new Finding('F1', Severity::P1, 'src/Cart.php', 1, null, 'Still wrong.', FixableBy::Builder, true, 'SOLID', null)], [], []));

        self::assertSame(self::OFF_THE_PAGES, $this->writersFailure());
        self::assertSame(['docs/user/cart.md'], array_map(static fn (TrackedFinding $tracked): ?string => $tracked->latest->file, $this->fixture->storedFindings(RoundFixture::CHANGE)));
    }

    public function testABlockedVerdictIntaktGivesTheTechnicalWriterIsRecordedWithItsOwnReason(): void
    {
        $this->deliverAll(Verdict::noTools());

        self::assertNull($this->writersFailure());
        self::assertStringContainsString('"'.Verdict::NO_TOOLS.'"', $this->writer()?->verdictJson() ?? '');
    }

    public function testARepeatOfTheTechnicalWriterThatIsNoProposalIsRecorded(): void
    {
        $this->deliverAll(new Verdict(Decision::Approve, [], [], []), new Verdict(Decision::Changes, [$this->blockingAt('docs/user/cart.md')], [], []));
        $this->startRound();

        $this->deliverAll(new Verdict(Decision::Changes, [new Finding('F1', Severity::P1, 'docs/user/cart.md', 1, null, 'Still wrong.', FixableBy::Builder, true, 'SOLID', null)], [], []));

        self::assertNull($this->writersFailure());
        self::assertSame(['F1'], $this->stillOpen());
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->startRound();
    }

    private function blockingAt(string $file): Finding
    {
        return new Finding('X', Severity::P1, $file, 1, null, 'Wrong.', FixableBy::Builder, false, 'SOLID', null);
    }

    private function startRound(): void
    {
        $model = new ReviewerModel(Tier::Standard, Provider::Anthropic);
        $this->fixture->startOnPanel(new Panel([
            new PanelReviewer(new ReviewerRole('correctness', 'Check the change.'), $model, 4),
            new PanelReviewer(new ReviewerRole(ReviewerRole::TECHNICAL_WRITER, 'Check the docs.'), $model, 4),
        ], QuorumPolicy::All));
    }

    private function writersFailure(): ?string
    {
        return $this->writer()?->failure();
    }

    private function writer(): ?RoundReviewer
    {
        $rounds = $this->fixture->storedRounds();

        return array_first(array_filter(array_last($rounds)?->reviewers() ?? [], static fn (RoundReviewer $reviewer): bool => ReviewerRole::TECHNICAL_WRITER === $reviewer->roleName()));
    }

    /**
     * @return list<string>
     */
    private function stillOpen(): array
    {
        return array_values(array_map(static fn (TrackedFinding $tracked): string => $tracked->id, array_filter($this->fixture->storedFindings(RoundFixture::CHANGE), static fn (TrackedFinding $tracked): bool => $tracked->isOpen())));
    }

    /**
     * @return list<array{ReviewLane, string}>
     */
    private function deliverAll(Verdict $writersVerdict, Verdict $codeVerdict = new Verdict(Decision::Approve, [], [], [])): array
    {
        $vendor = new class($writersVerdict, $codeVerdict) implements Vendor {
            /** @var list<array{ReviewLane, string}> */
            public array $requests = [];

            /** @var array<string, list<string>> */
            public array $open = [];

            public function __construct(private readonly Verdict $writersVerdict, private readonly Verdict $codeVerdict)
            {
            }

            #[Override]
            public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
            {
                $this->open[$request->conversation->lane->value] = array_map(static fn (Finding $finding): string => $finding->id, $request->brief->open);
                $this->requests[] = [$request->conversation->lane, $request->brief->change->diff];

                return new VendorReview(ReviewLane::Docs === $request->conversation->lane ? $this->writersVerdict : $this->codeVerdict);
            }

            #[Override]
            public function judge(string $project, JudgeRequest $request): Judgement
            {
                return Judgement::everyBlockingFindingActs($request->findings);
            }

            #[Override]
            public function mapsAModelTo(string $project, Tier $tier): bool
            {
                return true;
            }
        };

        foreach (array_slice($this->fixture->bus->messages, $this->delivered) as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        $this->delivered = count($this->fixture->bus->messages);
        $this->openGiven = $vendor->open;

        return $vendor->requests;
    }
}
