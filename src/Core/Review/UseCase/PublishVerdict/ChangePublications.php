<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Closure;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

// One publication per project and change at a time across the workers, held from reading the round through the forge write, because a forge status belongs to the change's commit and a write still on the way would otherwise replace a later round's status.
final readonly class ChangePublications
{
    public function __construct(
        private LockFactory $locks,
    ) {
    }

    // Not waited for, because Messenger brings the refused publication back, and it then reads the round as the holder left it.
    public function whileHeld(string $project, string $change, Closure $publish): void
    {
        $lock = $this->lockOf($project, $change);
        if (!$lock->acquire()) {
            throw PublicationBusy::of($change);
        }

        try {
            $publish();
        } finally {
            $lock->release();
        }
    }

    // Encoded as a list, so no project or change name can end where another begins.
    public function lockOf(string $project, string $change): LockInterface
    {
        return $this->locks->createLock('review-publication-'.hash('sha256', json_encode([$project, $change], JSON_THROW_ON_ERROR)));
    }
}
