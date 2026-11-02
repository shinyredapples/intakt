<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\ReviewProgress;
use Intakt\Core\Review\Contract\RoundProgress;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Sends a step only when it moved, because MCP progress values must increase; excluded because the tool builds it per call.
#[Exclude]
final class ForwardedProgress implements ReviewProgress
{
    private int $sent = -1;

    public function __construct(private readonly ClientGateway $client)
    {
    }

    public function reached(RoundProgress $progress): void
    {
        // The judge is one more step because the round is not done when the reviewers are.
        $step = $progress->reviewersFinished + ($progress->judged ? 1 : 0);

        if ($step <= $this->sent) {
            return;
        }

        $this->sent = $step;
        $this->client->progress($step, $progress->reviewers + 1, $this->messageFor($progress));
    }

    private function messageFor(RoundProgress $progress): string
    {
        if ($progress->judged) {
            return 'The round is judged.';
        }

        if ($progress->reviewersFinished === $progress->reviewers) {
            return 'All reviewers finished, the judge is reading their findings.';
        }

        return sprintf('%d of %d reviewers finished.', $progress->reviewersFinished, $progress->reviewers);
    }
}
