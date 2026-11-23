<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckPullRequestText;

use Closure;
use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Symfony\Component\Lock\LockFactory;

// The task's last pull request text line decides, so a miss right after a sent-back one goes to the reviewers, and a pass or a hand-off to the reviewers starts the count again; the hand-back round's lines are not read, so the two counts stay apart.
final readonly class PullRequestTextRoutes
{
    private const array ROUND = [LedgerEventKind::PullRequestTextPassed, LedgerEventKind::PullRequestTextSentBack, LedgerEventKind::PullRequestTextToReviewers];

    public function __construct(
        private Ledger $ledger,
        private LockFactory $locks,
    ) {
    }

    /**
     * @throws PullRequestTextRoundNotRecorded
     */
    public function passed(TaskBranch $task, PullRequestTextOutcome $outcome): void
    {
        $this->locked($task, function () use ($task, $outcome): void {
            $this->append(LedgerEventKind::PullRequestTextPassed, $this->payloadOf($task, $outcome));
        });
    }

    /**
     * @throws PullRequestTextRoundNotRecorded
     */
    public function failed(TaskBranch $task, PullRequestTextOutcome $outcome): FailedPullRequestText
    {
        return $this->locked($task, function () use ($task, $outcome): FailedPullRequestText {
            $route = LedgerEventKind::PullRequestTextSentBack === $this->lastOf($task) ? PullRequestTextRoute::ToReviewers : PullRequestTextRoute::SentBack;
            $this->append($this->kindOf($route), $this->payloadOf($task, $outcome));

            return new FailedPullRequestText($outcome, $route);
        });
    }

    /**
     * @template T
     *
     * @param Closure(): T $step
     *
     * @return T
     */
    private function locked(TaskBranch $task, Closure $step): mixed
    {
        // Held from the read to the append, because two rounds of one task read alike would both be sent back; a key of its own, so this round never waits on the hand-back round's lock; a list, because a branch or path may hold any separator.
        $lock = $this->locks->createLock('intakt-pull-request-text-'.hash('sha256', json_encode([$task->repository, $task->branch], JSON_THROW_ON_ERROR)));
        $lock->acquire(true);

        try {
            return $step();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{repository: string, branch: string, verdict: string, reason: string, missing: list<positive-int>, body_characters: int}
     */
    private function payloadOf(TaskBranch $task, PullRequestTextOutcome $outcome): array
    {
        return [
            'repository' => $task->repository,
            'branch' => $task->branch,
            'verdict' => $outcome->verdict->value,
            'reason' => $outcome->verdict->reason(),
            'missing' => $outcome->missingItems,
            'body_characters' => $outcome->bodyCharacters,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws PullRequestTextRoundNotRecorded
     */
    private function append(LedgerEventKind $kind, array $payload): void
    {
        $result = $this->ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));

        if (!$result->succeeded()) {
            throw PullRequestTextRoundNotRecorded::for($kind->value, (string) $result->failure);
        }
    }

    private function lastOf(TaskBranch $task): ?LedgerEventKind
    {
        $last = null;

        foreach ($this->ledger->readInOrder() as $recorded) {
            if (in_array($recorded->event->kind, self::ROUND, true) && $this->isOf($task, $recorded->event->payload)) {
                $last = $recorded->event->kind;
            }
        }

        return $last;
    }

    private function isOf(TaskBranch $task, string $payload): bool
    {
        $line = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        return is_array($line) && ($line['repository'] ?? null) === $task->repository && ($line['branch'] ?? null) === $task->branch;
    }

    private function kindOf(PullRequestTextRoute $route): LedgerEventKind
    {
        return match ($route) {
            PullRequestTextRoute::SentBack => LedgerEventKind::PullRequestTextSentBack,
            PullRequestTextRoute::ToReviewers => LedgerEventKind::PullRequestTextToReviewers,
        };
    }
}
