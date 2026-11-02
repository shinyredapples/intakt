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

    // Due at once, so a head the listing named is looked at in this tick and, should that fail, in the next.
    /**
     * @param list<OpenChange> $changed
     */
    public function enqueue(string $project, array $changed): void
    {
        $now = $this->clock->now();

        foreach ($changed as $open) {
            $pending = $this->stored($project, $open);

            if (!$pending instanceof PendingHead) {
                $this->entities->persist(new PendingHead($project, $open, $now));
            } else {
                $pending->wait($open, $now);
            }
        }

        $this->entities->flush();
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

    public function stillDue(string $project, OpenChange $open): ?OpenChange
    {
        $pending = $this->stored($project, $open);

        return true === $pending?->isDueAt($this->clock->now()) ? $pending->openChange() : null;
    }

    // Left alone when the listing queued a newer head meanwhile, because that head is due now and must not wait for this one's time.
    public function keep(string $project, HeadDecision $decision): void
    {
        $readAgainAt = $decision->readAgainAt ?? throw new LogicException('Only a pending head is kept.');
        $pending = $this->stored($project, $decision->change);

        if (true !== $pending?->isAt($decision->change)) {
            return;
        }

        $pending->wait($decision->change, $readAgainAt);
        $this->entities->flush();
    }

    // Removed only while the row still holds this head, in one statement, because the listing of another worker may have queued a newer head since this one was read.
    public function forget(string $project, OpenChange $settled): void
    {
        $this->entities->createQueryBuilder()
            ->delete(PendingHead::class, 'p')
            ->where('p.project = :project AND p.change = :change AND p.head = :head')
            ->setParameter('project', $project)
            ->setParameter('change', $settled->id->value)
            ->setParameter('head', $settled->head->hash)
            ->getQuery()
            ->execute();
        $this->dropLoaded($this->keyOf($project, $settled));
    }

    private function stored(string $project, OpenChange $open): ?PendingHead
    {
        $key = $this->keyOf($project, $open);
        $this->dropLoaded($key);

        return $this->entities->find(PendingHead::class, $key);
    }

    // Dropped so the next read goes to the database, because a worker keeps its entity manager while other workers change and remove rows.
    /**
     * @param array{project: string, change: string} $key
     */
    private function dropLoaded(array $key): void
    {
        $loaded = $this->entities->find(PendingHead::class, $key);

        if (null !== $loaded) {
            $this->entities->detach($loaded);
        }
    }

    /**
     * @return array{project: string, change: string}
     */
    private function keyOf(string $project, OpenChange $open): array
    {
        return ['project' => $project, 'change' => $open->id->value];
    }
}
