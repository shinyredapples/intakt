<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardServices;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnection;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnectionVerdict;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

// The board's last forge verdict, kept for a minute, because the check calls GitHub and a board load must not wait on it, or spend forge calls, on every render.
final readonly class RememberedForgeVerdict
{
    private const string KEY = 'forge-connection';
    private const int SECONDS = 60;

    public function __construct(
        private ForgeConnection $forge,
        #[Autowire(service: DashboardServices::FORGE_VERDICTS)]
        private CacheInterface $verdicts,
        private Redactor $redactor,
    ) {
    }

    public function latest(): ForgeConnectionVerdict
    {
        $kept = $this->verdicts->get(self::KEY, function (ItemInterface $item): ForgeConnectionVerdict {
            $item->expiresAfter(self::SECONDS);

            return $this->checked();
        });

        // Checked again, because the cache is a store outside this class and may hold another shape after an upgrade.
        return $kept instanceof ForgeConnectionVerdict ? $kept : $this->checked();
    }

    // Redacted before it is kept, because the problem can quote GitHub's answer and the cache is a file on disk.
    private function checked(): ForgeConnectionVerdict
    {
        $verdict = $this->forge->run();

        return null === $verdict->problem ? $verdict : $verdict->withProblem($this->redactor->redact($verdict->problem));
    }
}
