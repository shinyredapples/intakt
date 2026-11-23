<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckPullRequestText;

use Closure;
use Doctrine\DBAL\Exception;
use Intakt\Core\HandBack\Contract\FailedPullRequestText;
use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Contract\PullRequestTextRoundNotRecorded;
use Intakt\Core\HandBack\Contract\PullRequestTextRoute;
use Intakt\Core\HandBack\Contract\PullRequestTextTask;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use JsonException;
use PDOException;
use Symfony\Component\Lock\Exception\ExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

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
    public function passed(PullRequestTextTask $task, PullRequestTextOutcome $outcome): void
    {
        $this->locked($task, function () use ($task, $outcome): void {
            $this->append(LedgerEventKind::PullRequestTextPassed, $this->payloadOf($task, $outcome));
        });
    }

    /**
     * @throws PullRequestTextRoundNotRecorded
     */
    public function failed(PullRequestTextTask $task, PullRequestTextOutcome $outcome): FailedPullRequestText
    {
        return $this->locked($task, function () use ($task, $outcome): FailedPullRequestText {
            $route = LedgerEventKind::PullRequestTextSentBack === $this->lastOf($task) ? PullRequestTextRoute::ToReviewers : PullRequestTextRoute::SentBack;
            $this->append($this->kindOf($route), $this->payloadOf($task, $outcome));

            return new FailedPullRequestText($outcome, $route);
        });
    }

    /**
     * @return array{task: string, repository: string, branch: string, verdict: string, reason: string, missing: list<positive-int>, body_characters: int}
     */
    private function payloadOf(PullRequestTextTask $task, PullRequestTextOutcome $outcome): array
    {
        return [
            'task' => $task->key,
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
        // Bytes that are not UTF-8 in a path or a branch are shown substituted, because the task key, not these fields, tells one task's lines apart.
        $result = $this->ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)));

        if (!$result->succeeded()) {
            throw PullRequestTextRoundNotRecorded::for($kind->value, (string) $result->failure);
        }
    }

    /**
     * @template T
     *
     * @param Closure(): T $step
     *
     * @return T
     */
    private function locked(PullRequestTextTask $task, Closure $step): mixed
    {
        // Held from the read to the append, because two rounds of one task read alike would both be sent back; a key of its own, so this round never waits on the hand-back round's lock.
        $lock = $this->locks->createLock('intakt-pull-request-text-'.$task->key);

        try {
            $lock->acquire(true);
        } catch (ExceptionInterface $failure) {
            throw PullRequestTextRoundNotRecorded::unlocked($failure->getMessage());
        }

        try {
            return $step();
        } finally {
            $this->released($lock);
        }
    }

    private function released(SharedLockInterface $lock): void
    {
        try {
            $lock->release();
        } catch (ExceptionInterface) {
            // The round's line is written or refused by now, and the lock's time to live frees the key, so a failed release changes no route.
        }
    }

    /**
     * @throws PullRequestTextRoundNotRecorded
     */
    private function lastOf(PullRequestTextTask $task): ?LedgerEventKind
    {
        $last = null;

        try {
            foreach ($this->ledger->readMentioning(new Mentions($task->key)) as $recorded) {
                if (in_array($recorded->event->kind, self::ROUND, true) && $this->isOf($task, $recorded->event->payload)) {
                    $last = $recorded->event->kind;
                }
            }
        } catch (Exception|PDOException|JsonException $failure) {
            // Stopped rather than routed, because a guessed count would send a second miss back again or a first one to the reviewers.
            throw PullRequestTextRoundNotRecorded::unread($failure->getMessage());
        }

        return $last;
    }

    private function isOf(PullRequestTextTask $task, string $payload): bool
    {
        $line = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        return is_array($line) && ($line['task'] ?? null) === $task->key;
    }

    private function kindOf(PullRequestTextRoute $route): LedgerEventKind
    {
        return match ($route) {
            PullRequestTextRoute::SentBack => LedgerEventKind::PullRequestTextSentBack,
            PullRequestTextRoute::ToReviewers => LedgerEventKind::PullRequestTextToReviewers,
        };
    }
}
