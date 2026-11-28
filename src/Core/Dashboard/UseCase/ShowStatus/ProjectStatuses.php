<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowStatus;

use DateTimeImmutable;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Infrastructure\LedgerBuildSteps;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerWaitingHeads;
use Intakt\Core\Dashboard\Infrastructure\ParkedQuestionTexts;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\RoundEnds;
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
        private LedgerBuildSteps $buildSteps,
        private ParkedQuestionTexts $questions,
        private Redactor $redactor,
        private ReplayMarks $marks,
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
            'build_lane' => $this->buildLane($this->buildSteps->in($events)),
            'guard_decisions' => $this->guardDecisions($this->waitingHeads->in($events), $failing),
            'waiting_for_owner' => $this->waitingForOwner($board),
            'needs_you' => $this->needsYou($failing),
            'last_outcomes' => $this->lastOutcomes($board),
        ];

        return array_map(fn (string $name): array => [
            'name' => $name,
            'mark' => $this->marks->ofProject($name)->value,
            'lanes' => ['review' => $sections['review_lane'][$name] ?? [], 'build' => $sections['build_lane'][$name] ?? []],
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
            $round = $card->activeRound();

            if (!$round instanceof ReviewedRound) {
                continue;
            }

            $lane[$card->project][] = [
                'change' => $card->change,
                'mark' => $round->replayMark()->value,
                'round' => $round->round,
                'number' => $round->number,
                'started_at' => $round->startedAt->format(DATE_ATOM),
                'reviewers' => $round->reviewers,
                'steps' => array_map(static fn (ReviewerOutcome $outcome): array => ['role' => $outcome->role, 'tier' => $outcome->tier, 'progress' => $outcome->progress->value], $round->outcomes),
            ];
        }

        return $lane;
    }

    /**
     * @param list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}> $steps
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildLane(array $steps): array
    {
        $lane = [];

        foreach ($steps as $step) {
            $lane[$step['project']][] = [
                'task' => $step['task'],
                'step' => $step['step'],
                'outcome' => $step['outcome'],
                'failure' => null === $step['failure'] ? null : $this->redactor->redact($step['failure']),
                'ended_at' => $step['ended_at'],
                // Only the question of the run whose step is reported, because the task's row can hold the question of another run that overlapped it.
                'question' => BuildStepOutcome::Asked->value === $step['outcome'] ? $this->questions->of($step['project'], $step['task'], $step['run']) : null,
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
            $waiting[$card->project][] = ['change' => $card->change, 'mark' => $card->latest()->replayMark()->value, 'round' => $card->latest()->round];
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

    // The last ended round of every card, so a change in review again still shows how its previous round ended.
    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function lastOutcomes(Board $board): array
    {
        $ended = $this->endedRounds($board);
        usort($ended, static fn (ReviewedRound $one, ReviewedRound $other): int => self::endedAt($other) <=> self::endedAt($one));
        $outcomes = [];

        foreach ($ended as $round) {
            $outcomes[$round->project] ??= [];

            if (count($outcomes[$round->project]) < self::LAST_OUTCOMES) {
                $outcomes[$round->project][] = ['change' => $round->change, 'mark' => $round->replayMark()->value, 'round' => $round->round, 'number' => $round->number, 'end' => ($round->ending->end ?? RoundEnds::Judged)->value, 'decision' => $round->outcome?->value, 'judged_at' => $round->judgedAt?->format(DATE_ATOM)];
            }
        }

        return $outcomes;
    }

    // Any round of the card and not only the latest, because a round running after a failed judging must not hide that failure.
    /**
     * @return list<ReviewedRound>
     */
    private function endedRounds(Board $board): array
    {
        return array_values(array_filter(array_map(static fn (Review $card): ?ReviewedRound => array_last(array_values(array_filter($card->rounds, static fn (ReviewedRound $round): bool => $round->isJudged() || $round->hasEnded()))), $this->cardsOf($board))));
    }

    // A failure is placed by its ledger record rather than the round's start, because ten judgments since the start must not push a new failure off the list.
    private static function endedAt(ReviewedRound $round): DateTimeImmutable
    {
        return $round->judgedAt ?? $round->ending->endedAt ?? $round->startedAt;
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
