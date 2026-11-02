<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Closure;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Override;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

final readonly class ReviewerAgent implements Reviewer
{
    /**
     * @param Closure(Checkout): ToolboxInterface $toolboxes
     */
    public function __construct(
        private ReviewerPrompt $prompt,
        #[AutowireCallable(service: ReviewerToolboxFactory::class, method: 'create')]
        private Closure $toolboxes,
        private ReviewerRuns $runs,
    ) {
    }

    #[Override]
    public function review(ReviewRequest $request, PlatformInterface $platform, string $model): Verdict
    {
        $conversation = $this->runs->started($this->prompt->messagesFor($request->conversation, $request->brief));
        $tools = new MalformedToolCalls(($this->toolboxes)($request->checkout));
        $agent = $this->runs->agent($this->prompt->fenced(new FaultTolerantToolbox($tools)), $platform, $model);

        $execution = $agent->call($conversation, [PlatformSubscriber::RESPONSE_FORMAT => Verdict::class]);
        $verdict = $this->answerOf($this->runs->reporting($execution, $request->conversation));

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

    private function answerOf(ExecutionInterface $execution): Verdict
    {
        try {
            return $this->prompt->verdictIn($execution);
        } catch (MaxIterationsExceededException) {
            return Verdict::invalidOutput(sprintf('the reviewer was still asking for tools after %d rounds of tool calls.', ReviewerRuns::MAX_ROUNDS));
        }
    }
}
