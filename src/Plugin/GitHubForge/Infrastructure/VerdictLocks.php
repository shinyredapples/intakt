<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Closure;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\ReviewToken;
use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Symfony\Component\Lock\LockFactory;

// One publication of a round's verdict at a time across the workers, so two deliveries of one round cannot both find no comment and both post one.
final readonly class VerdictLocks
{
    public function __construct(
        private LockFactory $locks,
    ) {
    }

    // Not waited for, because the busy delivery fails, is retried and then finds the comment the other one posted.
    public function whileHeld(ReviewToken $round, Closure $publish): void
    {
        $lock = $this->locks->createLock(GitHubForgeServices::VERDICT_LOCK_PREFIX.$round->value);
        if (!$lock->acquire()) {
            throw new ForgeWriteFailed('Another delivery is publishing this round\'s verdict.');
        }

        try {
            $publish();
        } finally {
            $lock->release();
        }
    }
}
