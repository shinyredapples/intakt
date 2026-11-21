<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\AiLab;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndings;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\UseCase\ReviewChange\RoundEndReasons;
use Intakt\Tests\Support\FixedRoundEndings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DomCrawler\Crawler;

// The verdict card answers first (#3133): the outcome in plain words, the findings to fix and the panel, above everything else.
#[CoversClass(ShowReviewController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(AiLab::class)]
final class ReviewVerdictCardTest extends DashboardPageTestCase
{
    public function testAnApprovedRoundSaysApprovedInTheCardRightUnderTheTitle(): void
    {
        $this->givenARoundAnswered(0);

        $card = $this->card();

        self::assertSame('Approved', $card->filter('#review-verdict-state')->text());
        self::assertStringContainsString('vc-approved', (string) $card->attr('class'));
        self::assertCount(0, $card->filter('.vc-row'));
    }

    public function testARoundWithChangesNeededListsEachFindingToFixLinkedToItsCard(): void
    {
        $this->ledger->startRoundOfQuorumAll('feat/thing', 'round-1', 1);
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act', 'F2' => 'defer']);

        $card = $this->card();

        self::assertSame('Changes needed', $card->filter('#review-verdict-state')->text());
        self::assertSame(['#round-round-1-finding-F1'], $card->filter('a.vc-row')->each(static fn (Crawler $row): ?string => $row->attr('href')));
        self::assertSame('1 to fix', $card->filter('.vc-count strong')->text());
        self::assertSame('To fix', $card->filter('.vc-label .term')->getNode(0)?->firstChild?->textContent);
    }

    public function testARoundWithNoOutcomeInTimeIsBlockedWithItsReason(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        self::getContainer()->get(ClockInterface::class)->sleep(3 * 3600 + 1);

        $card = $this->card();

        self::assertSame('Blocked', $card->filter('#review-verdict-state')->text());
        self::assertStringContainsString(RoundEndReasons::NO_OUTCOME_IN_TIME, $card->filter('.vc-why')->text());
    }

    public function testARoundWhoseJudgingFailedSaysFailedInRed(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        self::getContainer()->set(RoundEndings::class, new FixedRoundEndings(new RoundEnding(RoundEnds::JudgingFailed, RoundEndReasons::JUDGING_FAILED)));

        $card = $this->card();

        self::assertSame('Failed', $card->filter('#review-verdict-state')->text());
        self::assertStringContainsString('vc-failed', (string) $card->attr('class'));
    }

    public function testAReplayCarriesTheReplayChipAndOffersNoNextRound(): void
    {
        $this->flagAsReplay('alpha');
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $page = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Replay', $page->filter('#review-verdict .replay-mark')->text());
        self::assertCount(0, $page->filter('form.start-review'));
    }

    public function testTheStoryNamesTheApprovingLabAndTheLabThatFoundTheFindings(): void
    {
        $this->ledger->startRoundOfQuorumAll('feat/thing', 'round-1', 1);
        $this->ledger->startReviewerOn('round-1', 'security', 'deepseek-ai/DeepSeek-V4-Pro-0813:deepinfra');
        $this->ledger->startReviewerOn('round-1', 'correctness', 'moonshotai/Kimi-K3.1-Pro:deepinfra');
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->answer('round-1', 'correctness', 4);
        $this->ledger->judgeRound('round-1');

        $card = $this->card();

        self::assertSame('The security reviewer (DeepSeek) approved; the correctness reviewer (Moonshot AI) found 4 findings.', $card->filter('.vc-story')->text());
        self::assertSame(['DeepSeek V4 Pro 0813 DeepSeek', 'Kimi K3.1 Pro Moonshot AI'], $card->filter('.vc-who')->each(static fn (Crawler $who): string => $who->text()));
        self::assertSame('moonshotai/Kimi-K3.1-Pro:deepinfra', $card->filter('.vc-reviewer')->last()->attr('title'));
    }

    public function testReviewersOfOneLabTellNoStory(): void
    {
        $this->ledger->startRoundOfQuorumAll('feat/thing', 'round-1', 1);
        $this->ledger->startReviewerOn('round-1', 'security', 'claude-opus-4-5');
        $this->ledger->startReviewerOn('round-1', 'correctness', 'claude-sonnet-4-5');
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->answer('round-1', 'correctness', 2);
        $this->ledger->judgeRound('round-1');

        self::assertCount(0, $this->card()->filter('.vc-story'));
    }

    private function givenARoundAnswered(int $findings): void
    {
        $this->ledger->startRoundOfQuorumAll('feat/thing', 'round-1', 1);
        $this->ledger->answer('round-1', 'correctness', $findings);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');
    }

    private function card(): Crawler
    {
        $page = $this->browser->request('GET', '/reviews/round-1');
        self::assertCount(1, $page->filter('.page-head + section#review-verdict'));

        return $page->filter('#review-verdict');
    }
}
