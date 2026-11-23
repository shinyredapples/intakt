<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Review\Contract\ReadAnswer;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Override;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Exception\RuntimeException;
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
    private const array UNSTREAMED = [PlatformSubscriber::RESPONSE_FORMAT => ReviewerAnswer::class];

    private const array STREAMED = self::UNSTREAMED + ['stream' => true];

    /**
     * @param Closure(ReviewRequest): ToolboxInterface $toolboxes
     */
    public function __construct(
        private ReviewerPrompt $prompt,
        #[AutowireCallable(service: ReviewerMcpToolboxFactory::class, method: 'create')]
        private Closure $toolboxes,
        private ReviewerRuns $runs,
        // Defaulted, because the checks have no state or settings to wire.
        private OpenFindingIds $ids = new OpenFindingIds(),
    ) {
    }

    #[Override]
    public function review(ReviewRequest $request, PlatformInterface $platform, ResolvedModel $model): Verdict
    {
        $tools = new MalformedToolCalls(($this->toolboxes)($request));

        // Before the model is asked, because a toolbox that cannot reach its server lists no tools and raises nothing, and a reviewer without them could approve code it never read.
        if ([] === $tools->getTools()) {
            return Verdict::noTools();
        }

        $conversation = $this->runs->started($this->prompt->messagesFor($request->conversation, $request->brief));
        $fenced = $this->prompt->fenced(new FaultTolerantToolbox($tools));
        $agent = $this->runs->agent($fenced, $platform, $model->model);

        $verdict = $this->canStream($platform, $model->model) ? $this->answeredStreaming($agent, $conversation, $request->conversation) : $this->answeredUnstreamed($agent, $conversation, $request->conversation);

        // A stop wins over an earlier malformed call, because only a stopped verdict lets the stop be recorded; a model naming the id itself gains nothing, since OpenFindingIds::refuseClaimedFailureIds() refuses a forged stop.
        if ($tools->seen() && !$verdict->isStopped()) {
            throw VerdictUnreadable::becauseKeySafe('the reviewer made a malformed tool call.');
        }

        // Before the cut approval is put in its place, because a reviewer that names a reserved id has given an unreadable answer whatever it decided, and is asked once more.
        $this->ids->refuseClaimedFailureIds($verdict);

        $counted = $this->ids->asTheRoundCounts($request->brief, $verdict);

        if ($this->approvesCutText($counted, $request->brief, $fenced)) {
            return Verdict::cutApproval();
        }

        return $this->ids->checkedAgainst($request->brief, $counted);
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
            return $this->answerOf($this->runs->reporting($stream->watching($agent->call($conversation, self::STREAMED)), $reviewer), $reviewer);
        } catch (BadRequestException|InvalidRequestException $refused) {
            $this->fellBackUnlessAccepted($stream, $refused, $reviewer);
        }

        return $this->answeredUnstreamed($agent, $conversation, $reviewer);
    }

    private function answeredUnstreamed(Agent $agent, MessageBag $conversation, ReviewerConversation $reviewer): Verdict
    {
        return $this->answerOf($this->runs->reporting($agent->call($conversation, self::UNSTREAMED), $reviewer), $reviewer);
    }

    // Plain code refuses it, because the note asking the reviewer to read the rest is only prompt text, and a steered or lazy reviewer could approve code it never saw.
    private function approvesCutText(Verdict $verdict, ReviewBrief $brief, FencedToolbox $fenced): bool
    {
        return $verdict->mayCountAsApproval() && ($this->prompt->cutsInputOf($brief) || $fenced->cutAnAnswer());
    }

    private function answerOf(ExecutionInterface $execution, ReviewerConversation $reviewer): Verdict
    {
        try {
            return $this->reportedAsRead($this->prompt->verdictIn($execution), $reviewer);
        } catch (VerdictUnreadable $unreadable) {
            // Only the marker, because the unread answer is the model's own text; the exception still carries it for debugging.
            $this->runs->answered($reviewer, ReadAnswer::unreadable());

            throw $unreadable;
        } catch (MaxIterationsExceededException) {
            throw VerdictUnreadable::becauseKeySafe(sprintf('the reviewer was still asking for tools after %d rounds of tool calls.', ReviewerRuns::MAX_ROUNDS));
        } catch (ReviewServerLost) {
            return Verdict::noTools();
        } catch (RuntimeException $failure) {
            // Read from the execution, never from the message, so only a run ReviewerStopping cancelled ends as stopped and any other failure stays an error.
            return ExecutionStatus::Cancelled === $execution->getStatus() ? Verdict::reviewerStopped() : throw $failure;
        }
    }

    private function fellBackUnlessAccepted(StreamAcceptance $stream, BadRequestException|InvalidRequestException $refused, ReviewerConversation $reviewer): void
    {
        if ($stream->accepted()) {
            throw $refused;
        }

        $this->runs->fellBackToUnstreamed($reviewer);
    }

    private function reportedAsRead(Verdict $verdict, ReviewerConversation $reviewer): Verdict
    {
        $this->runs->answered($reviewer, ReadAnswer::of($verdict));

        return $verdict;
    }
}
