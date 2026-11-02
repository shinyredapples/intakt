<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Closure;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\ConversationAlreadyStarted;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Review\Contract\Verdict;
use Override;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Exception\ConflictException;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;
use Symfony\Component\Uid\Uuid;

final readonly class ReviewerAgent implements Reviewer
{
    // Rounds, not calls, because the bill grows with every round that resends the conversation, however many files one round asks for; the agent's default of 50 is a long bill for a review that reads a handful of files.
    private const int MAX_ROUNDS = 30;

    /**
     * @param Closure(Checkout): ToolboxInterface $toolboxes
     */
    public function __construct(
        private ReviewerPrompt $prompt,
        #[AutowireCallable(service: ReviewerToolboxFactory::class, method: 'create')]
        private Closure $toolboxes,
        private MessageStoreInterface $conversations,
        private RoundUpdates $updates,
    ) {
    }

    #[Override]
    public function review(ReviewRequest $request, PlatformInterface $platform, string $model): Verdict
    {
        $conversation = $this->started($this->prompt->messagesFor($request->conversation, $request->brief));
        $tools = new MalformedToolCalls(($this->toolboxes)($request->checkout));

        $execution = $this->agent($tools, $platform, $model)->call($conversation, [PlatformSubscriber::RESPONSE_FORMAT => Verdict::class]);
        $verdict = $this->answerOf($this->reporting($execution, $request->conversation));

        if ($tools->seen()) {
            return Verdict::invalidOutput('the reviewer made a malformed tool call.');
        }

        return $this->checkedAgainst($request->brief, $verdict);
    }

    // Plain code checks the ids, because only the request knows which findings were open; an id the reviewer invented would otherwise close or revive a finding that never existed.
    private function checkedAgainst(ReviewBrief $brief, Verdict $verdict): Verdict
    {
        $open = array_map(static fn (Finding $finding): string => $finding->id, $brief->open);
        $repeated = array_map(static fn (Finding $finding): string => $finding->id, array_filter($verdict->findings, static fn (Finding $finding): bool => $finding->repeat));
        $unknown = array_unique([...array_diff($verdict->resolved, $open), ...array_diff($repeated, $open)]);

        if ([] !== $unknown) {
            return Verdict::invalidOutput(sprintf('the verdict names ids that were not open: %s.', implode(', ', $unknown)));
        }

        // Refused, because the ledger cannot tell which of the two the reviewer meant.
        $contradicted = array_unique(array_intersect($verdict->resolved, $repeated));

        return [] === $contradicted ? $verdict : Verdict::invalidOutput(sprintf('the verdict marks ids both resolved and repeat: %s.', implode(', ', $contradicted)));
    }

    // Saved before the agent runs because the store refuses them when another review stored its own under the same id, also when both started at once, so no reviewer continues another's conversation.
    private function started(MessageBag $conversation): MessageBag
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
    private function agent(ToolboxInterface $tools, PlatformInterface $platform, string $model): Agent
    {
        $toolbox = $this->prompt->fenced(new FaultTolerantToolbox($tools));

        return new Agent($platform, $model, name: 'reviewer', toolbox: $toolbox, maxToolCalls: self::MAX_ROUNDS, messageStore: $this->conversations);
    }

    // Reported as it happens and never read back, so the verdict path is the one it was without the live view.
    private function reporting(ExecutionInterface $execution, ReviewerConversation $reviewer): ExecutionInterface
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

    private function answerOf(ExecutionInterface $execution): Verdict
    {
        try {
            return $this->prompt->verdictIn($execution);
        } catch (MaxIterationsExceededException) {
            return Verdict::invalidOutput(sprintf('the reviewer was still asking for tools after %d rounds of tool calls.', self::MAX_ROUNDS));
        }
    }
}
