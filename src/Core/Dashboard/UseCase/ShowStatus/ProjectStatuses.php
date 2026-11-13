<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowStatus;

use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerWaitingHeads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Watcher\Contract\WatcherServices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Each section is grouped by project first and joined per project at the end, so a project the watcher lists but the ledger never named is still reported.
final readonly class ProjectStatuses
{
    private const int LAST_OUTCOMES = 10;

    /**
     * @param array<string, mixed> $watched
     */
    public function __construct(
        private Ledger $ledger,
        private LedgerReviewPages $reviewPages,
        private LedgerFailingHeads $failingHeads,
        private LedgerWaitingHeads $waitingHeads,
        private Redactor $redactor,
        #[Autowire(param: WatcherServices::PROJECTS)]
        private array $watched,
    ) {
    }

    // One read of the ledger for every section, so a line the watcher writes meanwhile cannot show one head both waiting and failed.
    /**
     * @return list<array<string, mixed>>
     */
    public function now(): array
    {
        $events = $this->ledger->readInOrder();
        $board = $this->reviewPages->boardOf($events);
        $failing = $this->failingHeads->in($events);
        $sections = [
            'review_lane' => $this->reviewLane($board),
            'guard_decisions' => $this->guardDecisions($this->waitingHeads->in($events), $failing),
            'waiting_for_owner' => $this->waitingForOwner($board),
            'needs_you' => $this->needsYou($failing),
            'last_outcomes' => $this->lastOutcomes($board),
        ];

        return array_map(static fn (string $name): array => [
            'name' => $name,
            'lanes' => ['review' => $sections['review_lane'][$name] ?? []],
            'guard_decisions' => $sections['guard_decisions'][$name] ?? [],
            'waiting_for_owner' => $sections['waiting_for_owner'][$name] ?? [],
            'needs_you' => $sections['needs_you'][$name] ?? [],
            'last_outcomes' => $sections['last_outcomes'][$name] ?? [],
        ], $this->projectNames($sections));
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function reviewLane(Board $board): array
    {
        $lane = [];

        foreach ($board->cardsIn(BoardColumn::InReview) as $card) {
            $round = $card->latest();
            $lane[$card->project][] = [
                'change' => $card->change,
                'round' => $round->round,
                'number' => $round->number,
                'started_at' => $round->startedAt->format(DATE_ATOM),
                'reviewers' => $round->reviewers,
                'steps' => array_map(static fn (ReviewerOutcome $outcome): array => ['role' => $outcome->role, 'tier' => $outcome->tier, 'progress' => $outcome->progress->value], $round->outcomes),
            ];
        }

        return $lane;
    }

    // A handed-over head is a Needs-you item instead, because the watcher no longer retries it on its own.
    /**
     * @param list<array{project: string, change: string, decision: 'pending'|'red', read_again_at: ?string}> $waitingHeads
     * @param list<FailingHead>                                                                               $failing
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function guardDecisions(array $waitingHeads, array $failing): array
    {
        $decisions = [];

        foreach ($waitingHeads as $waiting) {
            $decisions[$waiting['project']][] = ['change' => $waiting['change'], 'decision' => $waiting['decision'], 'cause' => null, 'failures' => null, 'read_again_at' => $waiting['read_again_at']];
        }

        foreach (array_filter($failing, static fn (FailingHead $head): bool => !$head->handedOver) as $head) {
            $decisions[$head->project][] = ['change' => $head->change, 'decision' => 'failed', 'cause' => $this->redactor->redact($head->fault->cause), 'failures' => $head->fault->failures, 'read_again_at' => $head->fault->retryAt->format(DATE_ATOM)];
        }

        return $decisions;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function waitingForOwner(Board $board): array
    {
        $waiting = [];

        foreach ($board->cardsIn(BoardColumn::WaitingOnYou) as $card) {
            $waiting[$card->project][] = ['change' => $card->change, 'round' => $card->latest()->round];
        }

        return $waiting;
    }

    /**
     * @param list<FailingHead> $failing
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function needsYou(array $failing): array
    {
        $items = [];

        foreach (array_filter($failing, static fn (FailingHead $head): bool => $head->handedOver) as $head) {
            $items[$head->project][] = ['change' => $head->change, 'cause' => $this->redactor->redact($head->fault->cause), 'failures' => $head->fault->failures];
        }

        return $items;
    }

    // The last judged round of every card, so a change in review again still shows how its previous round ended.
    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function lastOutcomes(Board $board): array
    {
        $judged = array_filter(array_map(static fn (Review $card): ?ReviewedRound => $card->lastJudgedRound(), $this->cardsOf($board)));
        usort($judged, static fn (ReviewedRound $one, ReviewedRound $other): int => $other->judgedAt <=> $one->judgedAt);
        $outcomes = [];

        foreach ($judged as $round) {
            $outcomes[$round->project] ??= [];

            if (count($outcomes[$round->project]) < self::LAST_OUTCOMES) {
                $outcomes[$round->project][] = ['change' => $round->change, 'round' => $round->round, 'number' => $round->number, 'decision' => $round->outcome?->value, 'judged_at' => $round->judgedAt?->format(DATE_ATOM)];
            }
        }

        return $outcomes;
    }

    /**
     * @return list<Review>
     */
    private function cardsOf(Board $board): array
    {
        return array_merge(...array_map($board->cardsIn(...), BoardColumn::cases()));
    }

    // Names are strings again here, because PHP turns a project name of digits into an integer key.
    /**
     * @param array<string, array<array-key, mixed>> $sections
     *
     * @return list<string>
     */
    private function projectNames(array $sections): array
    {
        $names = array_keys($this->watched);

        foreach ($sections as $byProject) {
            $names = [...$names, ...array_keys($byProject)];
        }

        $names = array_values(array_unique(array_map(strval(...), $names)));
        sort($names, SORT_STRING);

        return $names;
    }
}
