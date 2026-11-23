<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Closure;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Symfony\Component\Lock\LockFactory;

// The task's last hand-back line decides, so a failure right after a sent-back one is handed over, and an accepted or handed-over line starts the count again.
final readonly class HandBackRoutes
{
    private const array ROUND = [LedgerEventKind::HandBackAccepted, LedgerEventKind::HandBackSentBack, LedgerEventKind::HandBackHandedOver];

    public function __construct(
        private Ledger $ledger,
        private LockFactory $locks,
    ) {
    }

    /**
     * @throws HandBackRoundNotRecorded
     */
    public function accepted(HandBack $handBack, string $repository, AcceptedHandBack $accepted): void
    {
        $task = ['repository' => $repository, 'branch' => $handBack->branch];

        $this->locked($task, function () use ($task, $accepted): void {
            $this->append(LedgerEventKind::HandBackAccepted, $task + ['base' => $accepted->base, 'head' => $accepted->head]);
        });
    }

    /**
     * @throws HandBackRoundNotRecorded
     */
    public function failed(HandBack $handBack, string $repository, HandBackRefusal $refusal): FailedHandBack
    {
        $task = ['repository' => $repository, 'branch' => $handBack->branch];

        return $this->locked($task, function () use ($task, $refusal): FailedHandBack {
            $route = LedgerEventKind::HandBackSentBack === $this->lastOf($task) ? HandBackRoute::HandedOver : HandBackRoute::SentBack;
            $this->append($this->kindOf($route), $task + ['refusal' => $refusal->value, 'reason' => $refusal->reason()]);

            return new FailedHandBack($refusal, $route);
        });
    }

    /**
     * @param array{repository: string, branch: string} $task
     */
    private function lastOf(array $task): ?LedgerEventKind
    {
        $last = null;

        foreach ($this->ledger->readInOrder() as $recorded) {
            if (in_array($recorded->event->kind, self::ROUND, true) && $this->isOf($task, $recorded->event->payload)) {
                $last = $recorded->event->kind;
            }
        }

        return $last;
    }

    /**
     * @param array{repository: string, branch: string} $task
     */
    private function isOf(array $task, string $payload): bool
    {
        $line = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        return is_array($line) && ($line['repository'] ?? null) === $task['repository'] && ($line['branch'] ?? null) === $task['branch'];
    }

    private function kindOf(HandBackRoute $route): LedgerEventKind
    {
        return match ($route) {
            HandBackRoute::SentBack => LedgerEventKind::HandBackSentBack,
            HandBackRoute::HandedOver => LedgerEventKind::HandBackHandedOver,
        };
    }

    /**
     * @param array<string, string|null> $payload
     *
     * @throws HandBackRoundNotRecorded
     */
    private function append(LedgerEventKind $kind, array $payload): void
    {
        $result = $this->ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));

        if (!$result->succeeded()) {
            throw HandBackRoundNotRecorded::for($kind->value, (string) $result->failure);
        }
    }

    /**
     * @template T
     *
     * @param array{repository: string, branch: string} $task
     * @param Closure(): T                              $step
     *
     * @return T
     */
    private function locked(array $task, Closure $step): mixed
    {
        // Held from the read to the append, because two hand-backs of one task read alike would both be sent back; a list, because a branch or path may hold any separator.
        $lock = $this->locks->createLock('intakt-hand-back-'.hash('sha256', json_encode([$task['repository'], $task['branch']], JSON_THROW_ON_ERROR)));
        $lock->acquire(true);

        try {
            return $step();
        } finally {
            $lock->release();
        }
    }
}
