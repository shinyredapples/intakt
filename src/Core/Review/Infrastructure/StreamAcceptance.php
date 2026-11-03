<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Read from the agent's own progress, because a refused request means the provider cannot stream only before any streamed round was answered; after a delta or a tool call the stream was accepted, text was published and usage was spent.
#[Exclude]
final class StreamAcceptance
{
    private bool $accepted = false;

    public function watching(ExecutionInterface $execution): ExecutionInterface
    {
        return $execution->onProgress(function (Progress $progress): void {
            $this->accepted = $this->accepted || Progress::STAGE_MODEL_REQUEST !== $progress->getStage();
        });
    }

    public function accepted(): bool
    {
        return $this->accepted;
    }
}
