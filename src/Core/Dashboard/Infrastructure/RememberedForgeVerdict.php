<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardServices;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnection;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnectionVerdict;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

// The board's last forge verdict, kept for a minute, because the check calls GitHub and a board load must not wait on it, or spend forge calls, on every render.
final readonly class RememberedForgeVerdict
{
    private const string KEY = 'forge-connection';
    private const string LOCK = 'dashboard.forge-verdict';
    private const int SECONDS = 60;
    // Longer than the check's own 15 s deadline, so the lock outlives a check that runs to it.
    private const float LOCK_SECONDS = 30.0;

    public function __construct(
        private ForgeConnection $forge,
        #[Autowire(service: DashboardServices::FORGE_VERDICTS)]
        private CacheItemPoolInterface $verdicts,
        private Redactor $redactor,
        private LockFactory $locks,
    ) {
    }

    // Null while another board load runs the check, because only one load may call the forge for an expired verdict and the others must not wait on it.
    public function latest(): ?ForgeConnectionVerdict
    {
        $kept = $this->kept();
        if ($kept instanceof ForgeConnectionVerdict) {
            return $kept;
        }

        $lock = $this->locks->createLock(self::LOCK, self::LOCK_SECONDS);
        if (!$lock->acquire(false)) {
            return null;
        }

        try {
            // Read again under the lock, because the load that held it before may have just kept a verdict.
            return $this->kept() ?? $this->checkedAndKept();
        } finally {
            $lock->release();
        }
    }

    private function kept(): ?ForgeConnectionVerdict
    {
        $item = $this->verdicts->getItem(self::KEY);
        $kept = $item->get();

        // Only a verdict counts, because the cache is a store outside this class and may hold another shape after an upgrade.
        return $item->isHit() && $kept instanceof ForgeConnectionVerdict ? $kept : null;
    }

    private function checkedAndKept(): ForgeConnectionVerdict
    {
        $verdict = $this->checked();
        $this->verdicts->save($this->verdicts->getItem(self::KEY)->set($verdict)->expiresAfter(self::SECONDS));

        return $verdict;
    }

    // Redacted before it is kept, because the problem can quote GitHub's answer and the cache is a file on disk.
    private function checked(): ForgeConnectionVerdict
    {
        $verdict = $this->forge->run();

        return null === $verdict->problem ? $verdict : $verdict->withProblem($this->redactor->redact($verdict->problem));
    }
}
