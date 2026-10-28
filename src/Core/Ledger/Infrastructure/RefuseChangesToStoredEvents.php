<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Intakt\Core\Ledger\Contract\LedgerEventsAreAppendOnly;
use Intakt\Core\Ledger\Domain\EventRecord;

#[AsDoctrineListener(event: Events::onFlush)]
final readonly class RefuseChangesToStoredEvents
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $manager = $args->getObjectManager();
        $refusal = $this->refusalOf($manager->getUnitOfWork());

        if ($refusal === null) {
            return;
        }

        // The entity manager is shared, so a refused change left scheduled would refuse every later flush in this worker, the ledger's own appends too.
        $manager->clear();

        throw $refusal;
    }

    private function refusalOf(UnitOfWork $unitOfWork): ?LedgerEventsAreAppendOnly
    {
        if (array_any($unitOfWork->getScheduledEntityUpdates(), static fn (object $entity): bool => $entity instanceof EventRecord)) {
            return LedgerEventsAreAppendOnly::updated();
        }

        if (array_any($unitOfWork->getScheduledEntityDeletions(), static fn (object $entity): bool => $entity instanceof EventRecord)) {
            return LedgerEventsAreAppendOnly::deleted();
        }

        return null;
    }
}
