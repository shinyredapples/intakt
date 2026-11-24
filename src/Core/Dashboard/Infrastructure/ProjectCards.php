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
use Intakt\Core\Dashboard\Domain\ProjectOutcome;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\TierLine;
use Intakt\Core\Dashboard\Domain\WorkingCard;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Watcher\Contract\WatcherServices;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Every section comes from one read of the ledger, so one head never counts as both running and failing; a configured project the ledger never named still gets its card.
final readonly class ProjectCards
{
    private const int RECENT = 3;

    /**
     * @param array<string, mixed> $watched
     */
    public function __construct(
        private Ledger $ledger,
        private LedgerReviewPages $reviewPages,
        private LedgerFailingHeads $failingHeads,
        private TierResolver $tiers,
        private Redactor $redactor,
        #[Autowire(param: WatcherServices::PROJECTS)]
        private array $watched,
    ) {
    }

    /**
     * @return list<ProjectCard>
     */
    public function now(): array
    {
        $events = $this->ledger->readInOrder();
        $reviews = $this->reviewsOf($this->reviewPages->boardOf($events));
        $failing = $this->failingHeads->in($events);

        return array_map(fn (string $name): ProjectCard => $this->cardOf(
            $name,
            array_values(array_filter($reviews, static fn (Review $review): bool => $review->project === $name)),
            array_values(array_filter($failing, static fn (FailingHead $head): bool => $head->project === $name)),
        ), $this->namesOf($reviews, $failing));
    }

    /**
     * @param list<Review>      $reviews
     * @param list<FailingHead> $failing
     */
    private function cardOf(string $name, array $reviews, array $failing): ProjectCard
    {
        $retrying = array_values(array_filter($failing, static fn (FailingHead $head): bool => !$head->handedOver));
        $handedOver = count($failing) - count($retrying);
        $asking = count(array_filter($reviews, static fn (Review $review): bool => BoardColumn::WaitingOnYou === $review->column()));
        $running = array_values(array_filter($reviews, static fn (Review $review): bool => $review->activeRound() instanceof ReviewedRound));

        return new ProjectCard(
            $name,
            count($running),
            count(array_filter($reviews, static fn (Review $review): bool => BoardColumn::Done === $review->column())),
            count($retrying),
            $handedOver + $asking,
            [] === $failing ? null : $this->redactor->redact(array_first($failing)->fault->cause),
            array_reduce($reviews, static fn (TokenFigures $sum, Review $review): TokenFigures => $sum->plus($review->tokens()), TokenFigures::none()),
            array_reduce($reviews, static fn (Cost $sum, Review $review): Cost => $sum->plus($review->cost()), Cost::none()),
            array_map(WorkingCard::ofReview(...), array_filter($running, static fn (Review $review): bool => BoardColumn::InReview === $review->column())),
            $this->recentOutcomes($reviews),
            $this->tierLines($name),
        );
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
            static fn (ReviewedRound $round): ProjectOutcome => new ProjectOutcome($round->change, ReviewOutcome::of($round)?->plainWords() ?? 'Judged', $round->round),
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
                return new TierLine($tier->value, '-', $failure->getMessage());
            }

            return new TierLine($tier->value, $resolved->provider->value, $resolved->model);
        }, Tier::cases());
    }

    /**
     * @return list<Review>
     */
    private function reviewsOf(Board $board): array
    {
        return array_merge(...array_map($board->cardsIn(...), BoardColumn::cases()));
    }

    // Names are strings again here, because PHP turns a project name of digits into an integer key.
    /**
     * @param list<Review>      $reviews
     * @param list<FailingHead> $failing
     *
     * @return list<string>
     */
    private function namesOf(array $reviews, array $failing): array
    {
        $names = [
            ...array_map(strval(...), array_keys($this->watched)),
            ...array_map(static fn (Review $review): string => $review->project, $reviews),
            ...array_map(static fn (FailingHead $head): string => $head->project, $failing),
        ];
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }
}
