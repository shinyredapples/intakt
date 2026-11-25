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
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewLane;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\OwnerProposals;
use Intakt\Core\Review\Infrastructure\DocsPartOfDiff;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Support\KernelTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
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
    private RoundFixture $fixture;

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

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $model = new ReviewerModel(Tier::Standard, Provider::Anthropic);
        $this->fixture->startOnPanel(new Panel([
            new PanelReviewer(new ReviewerRole('correctness', 'Check the change.'), $model, 4),
            new PanelReviewer(new ReviewerRole(ReviewerRole::TECHNICAL_WRITER, 'Check the docs.'), $model, 4),
        ], QuorumPolicy::All));
    }

    /**
     * @return list<array{ReviewLane, string}>
     */
    private function deliverAll(Verdict $writersVerdict): array
    {
        $vendor = new class($writersVerdict) implements Vendor {
            /** @var list<array{ReviewLane, string}> */
            public array $requests = [];

            public function __construct(private readonly Verdict $writersVerdict)
            {
            }

            #[Override]
            public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
            {
                $this->requests[] = [$request->conversation->lane, $request->brief->change->diff];

                return new VendorReview(ReviewLane::Docs === $request->conversation->lane ? $this->writersVerdict : new Verdict(Decision::Approve, [], [], []));
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

        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $vendor->requests;
    }
}
