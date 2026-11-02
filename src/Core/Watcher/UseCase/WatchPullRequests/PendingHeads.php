<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Plugin\Forge\Contract\OpenChange;
use LogicException;
use Symfony\Component\Clock\ClockInterface;

final readonly class PendingHeads
{
    public function __construct(
        private EntityManagerInterface $entities,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<OpenChange>
     */
    public function dueFor(string $project): array
    {
        $now = $this->clock->now();
        $due = [];

        foreach ($this->entities->getRepository(PendingHead::class)->findBy(['project' => $project]) as $pending) {
            // Refreshed because a worker keeps its entity manager and another worker may have moved the row.
            $this->entities->refresh($pending);

            if ($pending->isDueAt($now)) {
                $due[] = $pending->openChange();
            }
        }

        return $due;
    }

    public function keep(string $project, HeadDecision $decision): void
    {
        $readAgainAt = $decision->readAgainAt ?? throw new LogicException('Only a pending head is kept.');
        $pending = $this->entities->find(PendingHead::class, ['project' => $project, 'change' => $decision->change->id->value]);

        if (null === $pending) {
            $this->entities->persist(new PendingHead($project, $decision->change->id->value, $decision->change, $readAgainAt));
        } else {
            $pending->wait($decision->change, $readAgainAt);
        }

        $this->entities->flush();
    }

    public function forget(string $project, OpenChange $change): void
    {
        $pending = $this->entities->find(PendingHead::class, ['project' => $project, 'change' => $change->id->value]);

        if (null === $pending) {
            return;
        }

        $this->entities->remove($pending);
        $this->entities->flush();
    }
}
