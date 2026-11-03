<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Closure;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Override;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Exception\RuntimeException as AgentRuntimeException;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\InvalidRequestException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

final readonly class ReviewerAgent implements Reviewer
{
    private const array UNSTREAMED = [PlatformSubscriber::RESPONSE_FORMAT => Verdict::class];

    private const array STREAMED = self::UNSTREAMED + ['stream' => true];

    /**
     * @param Closure(ReviewRequest): ToolboxInterface $toolboxes
     */
    public function __construct(
        private ReviewerPrompt $prompt,
        #[AutowireCallable(service: ReviewerMcpToolboxFactory::class, method: 'create')]
        private Closure $toolboxes,
        private ReviewerRuns $runs,
    ) {
    }

    #[Override]
    public function review(ReviewRequest $request, PlatformInterface $platform, string $model): Verdict
    {
        $tools = new MalformedToolCalls(($this->toolboxes)($request));

        // Before the model is asked, because a toolbox that cannot reach its server lists no tools and raises nothing, and a reviewer without them could approve code it never read.
        if ([] === $tools->getTools()) {
            return Verdict::noTools();
        }

        $conversation = $this->runs->started($this->prompt->messagesFor($request->conversation, $request->brief));
        $fenced = $this->prompt->fenced(new FaultTolerantToolbox($tools));
        $agent = $this->runs->agent($fenced, $platform, $model);

        $verdict = $this->canStream($platform, $model) ? $this->answeredStreaming($agent, $conversation, $request->conversation) : $this->answeredUnstreamed($agent, $conversation, $request->conversation);

        if ($tools->seen()) {
            return Verdict::invalidOutput('the reviewer made a malformed tool call.');
        }

        if ($this->approvesCutText($verdict, $request->brief, $fenced)) {
            return Verdict::cutApproval();
        }

        return $this->checkedAgainst($request->brief, $verdict);
    }

    // Plain code refuses it, because the note asking the reviewer to read the rest is only prompt text, and a steered or lazy reviewer could approve code it never saw.
    private function approvesCutText(Verdict $verdict, ReviewBrief $brief, FencedToolbox $fenced): bool
    {
        return Decision::Approve === $verdict->verdict && ($this->prompt->cutsInputOf($brief) || $fenced->cutAnAnswer());
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

    // Read from the model's catalog entry, never from model text, so the choice to stream is made by plain code.
    /**
     * @param non-empty-string $model
     */
    private function canStream(PlatformInterface $platform, string $model): bool
    {
        return $platform->getModelCatalog()->getModel($model)->supports(Capability::OUTPUT_STREAMING);
    }

    // Retried unstreamed only when the request is refused before the provider accepted a stream, because a provider reports that before its first delta, so no text was published and no usage was spent; a later refusal or a stream cut off mid-answer is never retried.
    private function answeredStreaming(Agent $agent, MessageBag $conversation, ReviewerConversation $reviewer): Verdict
    {
        $stream = new StreamAcceptance();

        try {
            return $this->answerOf($this->runs->reporting($stream->watching($agent->call($conversation, self::STREAMED)), $reviewer));
        } catch (BadRequestException|InvalidRequestException $refused) {
            $this->fellBackUnlessAccepted($stream, $refused, $reviewer);
        }

        return $this->answeredUnstreamed($agent, $conversation, $reviewer);
    }

    private function fellBackUnlessAccepted(StreamAcceptance $stream, BadRequestException|InvalidRequestException $refused, ReviewerConversation $reviewer): void
    {
        if ($stream->accepted()) {
            throw $refused;
        }

        $this->runs->fellBackToUnstreamed($reviewer);
    }

    private function answeredUnstreamed(Agent $agent, MessageBag $conversation, ReviewerConversation $reviewer): Verdict
    {
        return $this->answerOf($this->runs->reporting($agent->call($conversation, self::UNSTREAMED), $reviewer));
    }

    private function answerOf(ExecutionInterface $execution): Verdict
    {
        try {
            return $this->prompt->verdictIn($execution);
        } catch (MaxIterationsExceededException) {
            return Verdict::invalidOutput(sprintf('the reviewer was still asking for tools after %d rounds of tool calls.', ReviewerRuns::MAX_ROUNDS));
        } catch (ReviewServerLost) {
            return Verdict::noTools();
        } catch (AgentRuntimeException $failure) {
            // The execution reports itself cancelled only after a stop, so any other agent failure still surfaces as a failed reviewer.
            return ExecutionStatus::Cancelled === $execution->getStatus() ? Verdict::stopped() : throw $failure;
        }
    }
}
