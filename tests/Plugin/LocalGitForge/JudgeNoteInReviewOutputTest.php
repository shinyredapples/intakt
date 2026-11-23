<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Closure;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\OnePerPlace;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\ReviewChange\UpperWait;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\LocalGitForge\Infrastructure\VisiblePath;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewOutput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewReport;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\WorkerOnSleep;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

// A round reviewed and judged through ReviewChange, the use case behind intakt:review, then printed as the command prints it, so the note travels the whole way from the judge's answer.
#[Group('persistence')]
#[CoversClass(ReviewReport::class)]
#[CoversClass(ReviewOutput::class)]
#[UsesClass(ReviewChange::class)]
#[UsesClass(RoundLedgerReading::class)]
#[UsesClass(StoredFindings::class)]
#[UsesClass(OnePerPlace::class)]
#[UsesClass(ChangeReview::class)]
#[UsesClass(Finding::class)]
#[UsesClass(VisiblePath::class)]
final class JudgeNoteInReviewOutputTest extends KernelTestCase
{
    private const string REJECTED = 'The branch cannot be reached from any caller.';
    private const string DEFERRED = 'This belongs to the follow-up on retries.';

    private RoundFixture $fixture;

    public function testAJudgeAnswerThatRejectsAP1LeavesItOnTheActListWithTheJudgesNoteInTextAndJson(): void
    {
        $review = $this->reviewWith(new JudgeMarkingByText($this->reviewersReporting(Severity::P1), ['Text of A.' => [Mark::Reject, self::REJECTED]]));

        self::assertSame(self::REJECTED, $this->judgeNoteAfter($review, 'P1 src/A.php:7: Text of A.'));
        self::assertSame(self::REJECTED, $this->jsonRow($review, 'Text of A.')['judge_note'] ?? null);
    }

    public function testAJudgeAnswerThatDefersAP2LeavesItOnTheActListWithTheJudgesNoteInTextAndJson(): void
    {
        $review = $this->reviewWith(new JudgeMarkingByText($this->reviewersReporting(Severity::P2), ['Text of B.' => [Mark::Defer, self::DEFERRED]]));

        self::assertSame(self::DEFERRED, $this->judgeNoteAfter($review, 'P2 src/A.php:9: Text of B.'));
        self::assertSame(self::DEFERRED, $this->jsonRow($review, 'Text of B.')['judge_note'] ?? null);
    }

    public function testAP1WithoutAJudgeAnswerHasNoNoteInTextOrJson(): void
    {
        $review = $this->reviewWith(new JudgeMarkingByText($this->reviewersReporting(Severity::P2), ['Text of B.' => [Mark::Defer, self::DEFERRED]]));

        self::assertNull($this->judgeNoteAfter($review, 'P1 src/A.php:7: Text of A.'));
        self::assertArrayNotHasKey('judge_note', $this->jsonRow($review, 'Text of A.'));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    // Two places, so the judge's marks never meet in one merged entry.
    private function reviewersReporting(Severity $second): Vendor
    {
        return new VerdictPerRole([
            'Look at part 1.' => new Verdict(Decision::Changes, [new Finding('A', Severity::P1, 'src/A.php', 7, null, 'Text of A.', FixableBy::Builder, false, null, null)], [], []),
            'Look at part 2.' => new Verdict(Decision::Changes, [new Finding('B', $second, 'src/A.php', 9, null, 'Text of B.', FixableBy::Builder, false, null, null)], [], []),
        ]);
    }

    private function reviewWith(Vendor $vendor): ChangeReview
    {
        $delivered = 0;
        $work = function () use ($vendor, &$delivered): void {
            foreach (array_slice($this->fixture->bus->messages, $delivered) as $message) {
                $this->fixture->deliver($message, $vendor);
                ++$delivered;
            }

            foreach ($this->fixture->judgings() as $judging) {
                $this->fixture->judge($vendor)($judging);
            }
        };

        return $this->reviewChange($work, $vendor)->review($this->request());
    }

    private function judgeNoteAfter(ChangeReview $review, string $finding): ?string
    {
        $lines = $this->report()->asLines($review);
        $at = array_find_key($lines, static fn (string $line): bool => str_ends_with($line, ' '.$finding));
        self::assertIsInt($at, 'The act list should print '.$finding);
        $next = $lines[$at + 1] ?? '';

        return str_starts_with($next, 'judge: ') ? substr($next, strlen('judge: ')) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonRow(ChangeReview $review, string $text): array
    {
        /** @var array{act_list: list<array<string, mixed>>} $document */
        $document = json_decode($this->report()->asJson($review), true, flags: JSON_THROW_ON_ERROR);
        $row = array_find($document['act_list'], static fn (array $row): bool => $text === $row['text']);
        self::assertIsArray($row, 'The act list should hold '.$text);

        return $row;
    }

    private function reviewChange(Closure $work, Vendor $tiers): ReviewChange
    {
        $serializer = self::getContainer()->get('serializer.stored');
        $handler = $this->fixture->startHandlerOn($this->fixture->bus);

        return new ReviewChange(
            new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RunReviewRound::class => [$handler]]))], unwrapExceptions: true),
            $this->fixture->transactions(),
            new StoredFindings($this->fixture->entities, $serializer, $this->fixture->machine('state_machine.review_round')),
            new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices([])),
            new ChangeDecision(),
            new StoredVerdicts($serializer),
            new WorkerOnSleep($this->fixture->clock, $work),
            new FailureInWords(),
            $tiers,
            $this->fixture->noFailedJudgings(),
            new UpperWait(),
        );
    }

    private function request(): ChangeReviewRequest
    {
        $panel = new Panel(
            array_map(
                static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
                [1, 2],
            ),
            QuorumPolicy::All,
        );

        return new ChangeReviewRequest('alpha', __DIR__, RoundFixture::TASK, RoundFixture::DIFF, new RequestedRound(RoundFixture::CHANGE), SampleIdentity::of(RoundFixture::TASK, $panel), $panel);
    }

    private function report(): ReviewReport
    {
        return self::getContainer()->get(ReviewReport::class);
    }
}
