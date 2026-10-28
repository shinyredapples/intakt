<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Intakt\Core\Ledger\Contract\LedgerEventsAreAppendOnly;
use Intakt\Core\Ledger\Domain\EventRecord;

#[AsDoctrineListener(event: Events::onFlush)]
final readonly class RefuseChangesToStoredEvents
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        if (array_any($unitOfWork->getScheduledEntityUpdates(), static fn (object $entity): bool => $entity instanceof EventRecord)) {
            throw LedgerEventsAreAppendOnly::updated();
        }

        if (array_any($unitOfWork->getScheduledEntityDeletions(), static fn (object $entity): bool => $entity instanceof EventRecord)) {
            throw LedgerEventsAreAppendOnly::deleted();
        }
    }
}
