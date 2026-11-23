<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\Domain\HeadFailure;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\Domain\SeenChange;
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

        foreach ($this->entities->getRepository(PendingHead::class)->findBy(['project' => $project]) as $loaded) {
            $pending = $this->readAgain($loaded);

            if (true === $pending?->isDueAt($now)) {
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

    public function keep(string $project, HeadDecision $decision): void
    {
        $this->waitUntil($project, $decision->change, $decision->readAgainAt ?? throw new LogicException('Only a pending head is kept.'));
    }

    // Only while the row still holds this head, in one statement, because a newer head the listing queued meanwhile is due now and must not wait for this one's time; the failures stay, because a wait is no failure.
    public function waitUntil(string $project, OpenChange $head, DateTimeImmutable $until): void
    {
        $this->entities->createQueryBuilder()
            ->update(PendingHead::class, 'p')
            ->set('p.readAgainAt', ':readAgainAt')
            ->where('p.project = :project AND p.change = :change AND p.head = :head')
            ->setParameter('readAgainAt', PendingHead::momentOf($until))
            ->setParameter('project', $project)
            ->setParameter('change', $head->id->value)
            ->setParameter('head', $head->head->hash)
            ->getQuery()
            ->execute();
        $this->dropLoaded($this->keyOf($project, $head));
    }

    public function failuresOf(string $project, OpenChange $head): int
    {
        return $this->stored($project, $head)?->failuresOf($head) ?? 0;
    }

    // Only while the row still holds this head from the same listing, because a head the listing queued meanwhile, even the same commit touched again, is counted anew and due now; false when the row no longer holds it.
    public function backOff(HeadFailure $failure, int $failures, DateTimeImmutable $until): bool
    {
        $changed = $this->entities->createQueryBuilder()
            ->update(PendingHead::class, 'p')
            ->set('p.readAgainAt', ':readAgainAt')
            ->set('p.failures', ':failures')
            ->where('p.project = :project AND p.change = :change AND p.head = :head AND p.updatedAt = :updatedAt')
            ->setParameter('readAgainAt', PendingHead::momentOf($until))
            ->setParameter('failures', $failures)
            ->setParameter('project', $failure->project)
            ->setParameter('change', $failure->head->id->value)
            ->setParameter('head', $failure->head->head->hash)
            ->setParameter('updatedAt', PendingHead::momentOf($failure->head->updatedAt))
            ->getQuery()
            ->execute();
        $this->dropLoaded($this->keyOf($failure->project, $failure->head));

        return 1 === $changed;
    }

    // Reset only once a head was settled in full, because a step that fails after the read-again time was kept must still count toward the hand-over.
    public function clearFailures(string $project, OpenChange $settled): void
    {
        $this->entities->createQueryBuilder()
            ->update(PendingHead::class, 'p')
            ->set('p.failures', 0)
            ->where('p.project = :project AND p.change = :change AND p.head = :head AND p.failures > 0')
            ->setParameter('project', $project)
            ->setParameter('change', $settled->id->value)
            ->setParameter('head', $settled->head->hash)
            ->getQuery()
            ->execute();
        $this->dropLoaded($this->keyOf($project, $settled));
    }

    // Removed with the listing's record, which forgets a closed pull request, because a closed one is never listed again and its CI, often cancelled, would be read every minute for good; the forgotten heads are returned so the caller can record them.
    /**
     * @return list<OpenChange>
     */
    public function forgetClosed(string $project): array
    {
        /** @var list<PendingHead> $closed */
        $closed = $this->entities->createQuery(sprintf(
            'SELECT p FROM %s p WHERE p.project = :project AND NOT EXISTS (SELECT s.change FROM %s s WHERE s.project = p.project AND s.change = p.change)',
            PendingHead::class,
            SeenChange::class,
        ))
            ->setParameter('project', $project)
            // Refreshed, because a worker keeps its entity manager and a row it loaded earlier may have moved to another head since.
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        foreach ($closed as $pending) {
            $this->entities->remove($pending);
        }
        $this->entities->flush();

        return array_map(static fn (PendingHead $pending): OpenChange => $pending->openChange(), $closed);
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

    // Read again by its key rather than refreshed, because a worker keeps its entity manager, another worker may have moved or settled the row, and a refresh of a deleted row keeps its old values.
    private function readAgain(PendingHead $loaded): ?PendingHead
    {
        $this->entities->detach($loaded);

        return $this->entities->find(PendingHead::class, ['project' => $loaded->project(), 'change' => $loaded->change()]);
    }

    private function stored(string $project, OpenChange $open): ?PendingHead
    {
        $key = $this->keyOf($project, $open);
        $this->dropLoaded($key);

        return $this->entities->find(PendingHead::class, $key);
    }

    /**
     * @return array{project: string, change: string}
     */
    private function keyOf(string $project, OpenChange $open): array
    {
        return ['project' => $project, 'change' => $open->id->value];
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
}
