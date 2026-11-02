<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Symfony\Component\Lock\LockFactory;
use Throwable;

final readonly class StartedRounds
{
    public function __construct(
        private EntityManagerInterface $entities,
        private LockFactory $locks,
    ) {
    }

    /**
     * @param Closure(): string $start queues the round and returns its id
     *
     * @return string|null the id of the round, or null when this head already has one or another worker is starting it
     */
    public function startOnce(string $project, OpenChange $change, Closure $start): ?string
    {
        // Tried without waiting, because a tick has a time budget and the worker holding it starts the round anyway.
        $lock = $this->locks->createLock('watcher-round-'.hash('sha256', json_encode([$project, $change->id->value, $change->head->hash], JSON_THROW_ON_ERROR)));

        if (!$lock->acquire(false)) {
            return null;
        }

        try {
            return $this->claimAndStart($project, $change, $start);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<StartedRound> the rounds of the change on another head whose verdicts were not yet found stale
     */
    public function openRoundsBefore(string $project, OpenChange $change): array
    {
        $rounds = [];

        foreach ($this->entities->getRepository(StartedRound::class)->findBy(['project' => $project, 'change' => $change->id->value]) as $round) {
            // Refreshed because a worker keeps its entity manager and another worker may have changed the row.
            $this->entities->refresh($round);

            if (null !== $round->roundId() && !$round->isStale() && $round->head() !== $change->head->hash) {
                $rounds[] = $round;
            }
        }

        return $rounds;
    }

    public function markStale(StartedRound $round): void
    {
        $round->markStale();
        $this->entities->flush();
    }

    /**
     * @param Closure(): string $start
     */
    private function claimAndStart(string $project, OpenChange $change, Closure $start): ?string
    {
        $key = ['project' => $project, 'change' => $change->id->value, 'head' => $change->head->hash];

        if (null !== $this->entities->find(StartedRound::class, $key)) {
            return null;
        }

        $claim = new StartedRound($project, $change->id->value, $change->head->hash);
        $this->entities->persist($claim);
        $this->entities->flush();

        return $this->queue($claim, $start);
    }

    /**
     * @param Closure(): string $start
     */
    private function queue(StartedRound $claim, Closure $start): string
    {
        try {
            $roundId = $start();
        } catch (Throwable $failure) {
            // Released so the next tick tries this head again.
            $this->entities->remove($claim);
            $this->entities->flush();

            throw $failure;
        }

        $claim->queuedAs($roundId);
        $this->entities->flush();

        return $roundId;
    }
}
