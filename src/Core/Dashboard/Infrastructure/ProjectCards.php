<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\ProjectCard;
use Intakt\Core\Dashboard\Domain\ProjectOrder;
use Intakt\Core\Dashboard\Domain\ProjectOutcome;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\TierLine;
use Intakt\Core\Dashboard\Domain\WorkingCard;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;

// Every figure comes from one read of the ledger, so one head never counts as both running and failing and a cost never holds a call its tokens lack; the replay marks, how an unjudged round ended and the tiers are read apart. A configured project the ledger never named still gets its card.
final readonly class ProjectCards
{
    private const int RECENT = 3;

    public function __construct(
        private Ledger $ledger,
        private LedgerReviewPages $reviewPages,
        private LedgerFailingHeads $failingHeads,
        private TierResolver $tiers,
        private Redactor $redactor,
        private ConfiguredProjects $configured,
        private RoundPayloads $payloads,
    ) {
    }

    /**
     * @return list<ProjectCard>
     */
    public function now(): array
    {
        $events = $this->ledger->readInOrder();
        $reviews = $this->judgedLastFirst($this->reviewsOf($this->reviewPages->boardOf($events)), $events);
        $failing = $this->failingHeads->in($events);

        return array_map(fn (string $name): ProjectCard => $this->cardOf(
            $name,
            array_values(array_filter($reviews, static fn (Review $review): bool => $review->project === $name)),
            array_values(array_filter($failing, static fn (FailingHead $head): bool => $head->project === $name)),
        ), $this->namesOf($reviews));
    }

    /**
     * @return list<Review>
     */
    private function reviewsOf(Board $board): array
    {
        return array_merge(...array_map($board->cardsIn(...), BoardColumn::cases()));
    }

    // Equal judging times keep the ledger's order of judgments this way, because the sort of the last decided changes keeps the order it is given on a tie and the judgment recorded last must never be the one cut off.
    /**
     * @param list<Review> $reviews
     *
     * @return list<Review>
     */
    private function judgedLastFirst(array $reviews, RecordedEvents $events): array
    {
        $order = [];
        $place = 0;

        foreach ($events as $recorded) {
            $judge = LedgerEventKind::JudgeDone === $recorded->event->kind ? $this->payloads->judgeOf($recorded) : null;

            if ($judge instanceof JudgeDone) {
                $order[$judge->round] ??= $place;
            }

            ++$place;
        }

        usort($reviews, static fn (Review $one, Review $other): int => ($order[$other->lastJudgedRound()->round ?? ''] ?? -1) <=> ($order[$one->lastJudgedRound()->round ?? ''] ?? -1));

        return $reviews;
    }

    /**
     * @param list<Review>      $reviews
     * @param list<FailingHead> $failing
     */
    private function cardOf(string $name, array $reviews, array $failing): ProjectCard
    {
        // A handed-over head waits on the Maintainer, not on the owner, and is no longer retried, so it neither fails the card nor counts in its amber link.
        $retrying = array_values(array_filter($failing, static fn (FailingHead $head): bool => !$head->handedOver));
        $running = array_values(array_filter($reviews, static fn (Review $review): bool => $review->activeRound() instanceof ReviewedRound));

        return new ProjectCard(
            $name,
            count($running),
            count(array_filter($reviews, static fn (Review $review): bool => BoardColumn::Done === $review->column())),
            count($retrying),
            count(array_filter($reviews, static fn (Review $review): bool => BoardColumn::WaitingOnYou === $review->column())),
            [] === $retrying ? null : $this->redactor->redact(array_first($retrying)->fault->cause),
            array_reduce($reviews, static fn (TokenFigures $sum, Review $review): TokenFigures => $sum->plus($review->tokens()), TokenFigures::none()),
            array_reduce($reviews, static fn (Cost $sum, Review $review): Cost => $sum->plus($review->cost()), Cost::none()),
            array_map(WorkingCard::ofReview(...), $this->newestFirst(array_values(array_filter($running, static fn (Review $review): bool => BoardColumn::InReview === $review->column())))),
            $this->recentOutcomes($reviews),
            $this->tierLines($name),
        );
    }

    // In the board's order, because the working cards read like the board's and the reviews come here in the order of their judgments.
    /**
     * @param list<Review> $reviews
     *
     * @return list<Review>
     */
    private function newestFirst(array $reviews): array
    {
        usort($reviews, static fn (Review $one, Review $other): int => $other->latest()->startedAt <=> $one->latest()->startedAt);

        return $reviews;
    }

    // The newest first, because the board orders its cards so and the owner reads what ended last.
    /**
     * @param list<Review> $reviews
     *
     * @return list<ProjectOutcome>
     */
    private function recentOutcomes(array $reviews): array
    {
        $ended = array_filter(array_map(static fn (Review $review): ?ReviewedRound => $review->lastJudgedRound(), $reviews));
        usort($ended, static fn (ReviewedRound $one, ReviewedRound $other): int => ($other->judgedAt ?? $other->startedAt) <=> ($one->judgedAt ?? $one->startedAt));

        return array_map(
            static fn (ReviewedRound $round): ProjectOutcome => new ProjectOutcome($round->title(), ReviewOutcome::of($round)?->plainWords() ?? 'Judged', $round->round),
            array_slice($ended, 0, self::RECENT),
        );
    }

    /**
     * @return list<TierLine>
     */
    private function tierLines(string $project): array
    {
        return array_map(function (Tier $tier) use ($project): TierLine {
            try {
                $resolved = $this->tiers->resolve($project, $tier);
            } catch (TierResolutionFailure $failure) {
                return new TierLine($tier->value, '-', $this->redactor->redact($failure->getMessage()));
            }

            return new TierLine($tier->value, $resolved->provider->value, $resolved->model);
        }, Tier::cases());
    }

    /**
     * @param list<Review> $reviews
     *
     * @return list<string>
     */
    // Only names the project filter lists too, because a card links to the filter's choice of its project; a failing head of any other project adds no card, because the filter runs on every page and would need one more read of the ledger for it.
    private function namesOf(array $reviews): array
    {
        return ProjectOrder::sorted([
            ...$this->configured->names(),
            ...array_map(static fn (Review $review): string => $review->project, $reviews),
        ]);
    }
}
