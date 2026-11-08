<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\RoundUpdates;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

// Apart from ReviewerRuns, because what the dashboard sees of a running reviewer (its tool calls, its text and their pace) changes for other reasons than how the agent runs.
final readonly class LiveProgress
{
    public function __construct(
        private RoundUpdates $updates,
        private ClockInterface $clock,
    ) {
    }

    // Reported as it happens and never read back, so the verdict path is the one it was without the live view.
    public function reporting(ExecutionInterface $execution, ReviewerConversation $reviewer): ExecutionInterface
    {
        $round = $reviewer->round;

        if (!$round instanceof Uuid) {
            return $execution;
        }

        // The ledger's reviewer id when there is one, because the dashboard places a reviewer by it and a retry runs under a new conversation id.
        $name = ($reviewer->reviewerId ?? $reviewer->id)->toRfc4122();
        $text = new StreamedTextBatch(fn (string $piece) => $this->updates->textStreamed($round, $name, $piece), $this->clock);

        return $execution->onProgress(function (Progress $progress) use ($round, $name, $text): void {
            $payload = $progress->getPayload();

            if ($payload instanceof ToolCall) {
                $this->updates->toolCalled($round, $name, $payload->getName());
            }

            if ($payload instanceof TextDelta) {
                $text->add($payload->getText());
            }
        })->onResult($text->flush(...));
    }
}
