<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Closure;
use Intakt\Core\Platform\Contract\KeptForTheProcess;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

// An install without an App (the demo) publishes nothing to GitHub, because its outputs are the review page and MCP, so a missing App is a fact to report once, not a failure to retry.
final class GitHubAppSetting
{
    // Per-process state on purpose: the notice is once per start, so a reset between messages would bring back one line per review.
    #[KeptForTheProcess]
    private bool $reported = false;

    public function __construct(
        private readonly string $appId,
        private readonly LoggerInterface $logger,
    ) {
    }

    // At the worker's start too, because a project in live mode (the demo) never reaches the forge, and the notice belongs to every start without an App.
    #[AsEventListener(event: WorkerStartedEvent::class)]
    public function onWorkerStarted(): void
    {
        if ($this->isMissing()) {
            $this->reportMissingAppOnce();
        }
    }

    /**
     * @param Closure(): void $call
     */
    public function whenConfigured(Closure $call): bool
    {
        if ($this->isMissing()) {
            $this->reportMissingAppOnce();

            return false;
        }

        $call();

        return true;
    }

    private function isMissing(): bool
    {
        return '' === trim($this->appId);
    }

    private function reportMissingAppOnce(): void
    {
        if ($this->reported) {
            return;
        }

        $this->reported = true;
        $this->logger->info('No GitHub App is configured (GITHUB_APP_ID is empty), so verdicts are not published to GitHub; the review page and MCP show them.');
    }
}
