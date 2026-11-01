<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Closure;
use Symfony\Contracts\Service\ResetInterface;

// Holds the cleanup of the work in progress, because a signal ends the process without running the finally that would clean up.
final class CleanupOnStop implements ResetInterface
{
    private ?Closure $cleanup = null;

    /**
     * @template T
     *
     * @param Closure(): void $cleanup
     * @param Closure(): T    $work
     *
     * @return T
     */
    public function during(Closure $cleanup, Closure $work): mixed
    {
        $this->cleanup = $cleanup;

        try {
            return $work();
        } finally {
            $this->cleanup = null;
        }
    }

    public function stop(): void
    {
        $cleanup = $this->cleanup;
        $this->cleanup = null;

        if ($cleanup instanceof Closure) {
            $cleanup();
        }
    }

    public function reset(): void
    {
        $this->cleanup = null;
    }
}
