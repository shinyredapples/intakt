<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Closure;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

// An install without an App (the demo) publishes nothing to GitHub, because its outputs are the review page and MCP, so a missing App is a fact to report once, not a failure to retry.
final readonly class GitHubAppSetting
{
    // Per-process state on purpose: the notice is once per start, so a reset between messages would bring back one line per review. A readonly property holds a mutable marker to keep the stateful-service gate honest.
    private stdClass $notice;

    public function __construct(
        private string $appId,
        private LoggerInterface $logger,
    ) {
        $this->notice = new stdClass();
    }

    // At the worker's start too, because a project in live mode (the demo) never reaches the forge, and the notice belongs to every start without an App.
    #[AsEventListener(event: WorkerStartedEvent::class)]
    public function onWorkerStarted(): void
    {
        if ('' === trim($this->appId)) {
            $this->reportMissingAppOnce();
        }
    }

    /**
     * @param Closure(): void $call
     */
    public function whenConfigured(Closure $call): void
    {
        if ('' === trim($this->appId)) {
            $this->reportMissingAppOnce();

            return;
        }

        $call();
    }

    private function reportMissingAppOnce(): void
    {
        if (isset($this->notice->reported)) {
            return;
        }

        $this->notice->reported = true;
        $this->logger->info('No GitHub App is configured (GITHUB_APP_ID is empty), so verdicts are not published to GitHub; the review page and MCP show them.');
    }
}
