<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ConversationAlreadyStarted;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\RoundUpdates;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Exception\ConflictException;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Uid\Uuid;

// How a reviewer's agent runs: its conversation kept for the next round, its rounds capped, and what it does shown as it happens.
final readonly class ReviewerRuns
{
    // Rounds, not calls, because the bill grows with every round that resends the conversation, however many files one round asks for; the agent's default of 50 is a long bill for a review that reads a handful of files.
    public const int MAX_ROUNDS = 30;

    public function __construct(
        private MessageStoreInterface $conversations,
        private RoundUpdates $updates,
    ) {
    }

    // Saved before the agent runs because the store refuses them when another review stored its own under the same id, also when both started at once, so no reviewer continues another's conversation.
    public function started(MessageBag $conversation): MessageBag
    {
        try {
            $this->conversations->save($conversation);
        } catch (ConflictException $taken) {
            throw ConversationAlreadyStarted::for($conversation->getId(), $taken);
        }

        return $conversation;
    }

    /**
     * @param non-empty-string $model
     */
    public function agent(ToolboxInterface $toolbox, PlatformInterface $platform, string $model): Agent
    {
        return new Agent($platform, $model, name: 'reviewer', toolbox: $toolbox, maxToolCalls: self::MAX_ROUNDS, messageStore: $this->conversations);
    }

    // Reported as it happens and never read back, so the verdict path is the one it was without the live view.
    public function reporting(ExecutionInterface $execution, ReviewerConversation $reviewer): ExecutionInterface
    {
        $round = $reviewer->round;

        if (!$round instanceof Uuid) {
            return $execution;
        }

        return $execution->onProgress(function (Progress $progress) use ($round, $reviewer): void {
            $payload = $progress->getPayload();

            if ($payload instanceof ToolCall) {
                $this->updates->toolCalled($round, $reviewer->id->toRfc4122(), $payload->getName());
            }

            if ($payload instanceof TextDelta) {
                $this->updates->textStreamed($round, $reviewer->id->toRfc4122(), $payload->getText());
            }
        });
    }
}
