<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Closure;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\ConversationAlreadyStarted;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Override;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Exception\ConflictException;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

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
    ) {
    }

    #[Override]
    public function review(ReviewRequest $request, PlatformInterface $platform, string $model): Verdict
    {
        $conversation = $this->started($this->prompt->messagesFor($request->conversation, $request->task));
        $tools = new MalformedToolCalls(($this->toolboxes)($request->checkout));

        $verdict = $this->answerOf($this->agent($tools, $platform, $model)->call($conversation, [PlatformSubscriber::RESPONSE_FORMAT => Verdict::class]));

        return $tools->seen() ? Verdict::invalidOutput('the reviewer made a malformed tool call.') : $verdict;
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

    private function answerOf(ExecutionInterface $execution): Verdict
    {
        try {
            return $this->prompt->verdictIn($execution);
        } catch (MaxIterationsExceededException) {
            return Verdict::invalidOutput(sprintf('the reviewer was still asking for tools after %d rounds of tool calls.', self::MAX_ROUNDS));
        }
    }
}
