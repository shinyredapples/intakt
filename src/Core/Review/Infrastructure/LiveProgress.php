<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ReadAnswer;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewTool;
use Intakt\Core\Review\Contract\RoundUpdates;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Uid\Uuid;

// Apart from ReviewerRuns, because what the dashboard sees of a running reviewer (its tool calls, its text and its answer) changes for other reasons than how the agent runs.
final readonly class LiveProgress
{
    public function __construct(
        private RoundUpdates $updates,
    ) {
    }

    // Reported as it happens and never read back, so the verdict path is the one it was without the live view.
    public function reporting(ExecutionInterface $execution, ReviewerConversation $reviewer): ExecutionInterface
    {
        $round = $reviewer->round;

        if (!$round instanceof Uuid) {
            return $execution;
        }

        $name = $this->nameOf($reviewer);
        if ($reviewer->askedAgain) {
            $this->updates->reviewerAskedAgain($round, $name, $reviewer->id->toRfc4122());
        }

        $text = new StreamedTextBatch(fn (string $turn) => $this->updates->textStreamed($round, $name, $turn));

        return $execution->onProgress(function (Progress $progress) use ($round, $name, $text): void {
            $payload = $progress->getPayload();

            if ($payload instanceof ToolCall) {
                $text->release();
                $this->updates->toolCalled($round, $name, $this->inWords($payload));
            }

            if ($payload instanceof TextDelta) {
                $text->add($payload->getText());
            }
        });
    }

    public function answered(ReviewerConversation $reviewer, ReadAnswer $answer): void
    {
        $round = $reviewer->round;

        if ($round instanceof Uuid) {
            $this->updates->answerRead($round, $this->nameOf($reviewer), $answer);
        }
    }

    // The ledger's reviewer id when there is one, because the dashboard places a reviewer by it and a retry runs under a new conversation id.
    private function nameOf(ReviewerConversation $reviewer): string
    {
        return ($reviewer->reviewerId ?? $reviewer->id)->toRfc4122();
    }

    // The prefix is dropped, because over MCP the tools carry the server's prefix (review_read_file) and the live log names neither.
    private function inWords(ToolCall $call): string
    {
        $name = $call->getName();
        $tool = ReviewTool::tryFrom(str_starts_with($name, ReviewerMcpToolboxFactory::TOOL_PREFIX) ? substr($name, strlen(ReviewerMcpToolboxFactory::TOOL_PREFIX)) : $name);

        return $tool?->callInWords($call) ?? 'used a tool';
    }
}
