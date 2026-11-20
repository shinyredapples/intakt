<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\FindChangedPullRequests;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Watcher\Contract\ListingInProgress;
use Intakt\Core\Watcher\Domain\ListingValidator;
use Intakt\Core\Watcher\Domain\SeenChange;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Symfony\Component\Lock\LockFactory;
use Throwable;

final readonly class SeenListings
{
    public function __construct(
        private EntityManagerInterface $entities,
        private LockFactory $locks,
    ) {
    }

    /**
     * @template T
     *
     * @param Closure(): T $listing
     *
     * @return T
     *
     * @throws ListingInProgress
     * @throws Throwable
     */
    public function whileListing(string $project, Closure $listing): mixed
    {
        // Held across the listing because two listings of one project would both return the same pull request as changed.
        $lock = $this->locks->createLock('watcher-listing-'.hash('sha256', $project));

        if (!$lock->acquire(false)) {
            throw ListingInProgress::for($project);
        }

        try {
            $this->forgetRowsOf($project);

            return $listing();
        } finally {
            $lock->release();
        }
    }

    public function validatorOf(string $project): ?string
    {
        return $this->entities->find(ListingValidator::class, $project)?->value();
    }

    /**
     * @param list<OpenChange> $open
     *
     * @return list<OpenChange>
     */
    public function changedAmong(string $project, array $open): array
    {
        $changed = [];

        foreach ($open as $change) {
            $seen = $this->entities->find(SeenChange::class, ['project' => $project, 'change' => $change->id->value]);

            if (true !== $seen?->isStillAt($change)) {
                $changed[] = $change;
            }
        }

        return $changed;
    }

    /**
     * @return list<OpenChange>
     */
    public function openOf(string $project): array
    {
        return array_map(static fn (SeenChange $seen): OpenChange => $seen->asOpenChange(), $this->entities->getRepository(SeenChange::class)->findBy(['project' => $project], ['change' => 'ASC']));
    }

    /**
     * @param list<OpenChange> $open
     */
    public function record(string $project, array $open, ?string $validator): void
    {
        $this->forgetClosed($project, $open);

        foreach ($open as $change) {
            $this->recordSeen($project, $change);
        }

        $this->recordValidator($project, $validator);
        $this->entities->flush();
    }

    // Detached one by one, never cleared, because a worker keeps its entity manager between listings and what another listing stored since is the truth, while the caller's own pending work must survive.
    private function forgetRowsOf(string $project): void
    {
        foreach ($this->entities->getUnitOfWork()->getIdentityMap() as $managed) {
            foreach ($managed as $entity) {
                if (($entity instanceof ListingValidator || $entity instanceof SeenChange) && $entity->project() === $project) {
                    $this->entities->detach($entity);
                }
            }
        }
    }

    /**
     * @param list<OpenChange> $open
     */
    private function forgetClosed(string $project, array $open): void
    {
        // Forgotten so a pull request that is closed and opened again is looked at as new, and the table does not grow with every pull request ever listed.
        $stillOpen = array_map(static fn (OpenChange $change): string => $change->id->value, $open);

        foreach ($this->entities->getRepository(SeenChange::class)->findBy(['project' => $project]) as $seen) {
            if (!in_array($seen->change(), $stillOpen, true)) {
                $this->entities->remove($seen);
            }
        }
    }

    private function recordSeen(string $project, OpenChange $change): void
    {
        $seen = $this->entities->find(SeenChange::class, ['project' => $project, 'change' => $change->id->value]);

        if (null === $seen) {
            $this->entities->persist(new SeenChange($project, $change->id->value, $change));

            return;
        }

        $seen->see($change);
    }

    // A forge that sends no validator leaves none stored, so the next listing is a full one.
    private function recordValidator(string $project, ?string $validator): void
    {
        $stored = $this->entities->find(ListingValidator::class, $project);

        if (null === $validator) {
            if (null !== $stored) {
                $this->entities->remove($stored);
            }

            return;
        }

        if (null === $stored) {
            $this->entities->persist(new ListingValidator($project, $validator));

            return;
        }

        $stored->replace($validator);
    }
}
