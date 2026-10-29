<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Closure;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Override;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

final readonly class ReviewerAgent implements Reviewer
{
    // Because a reviewer that keeps asking for tools never answers: a review reads a handful of files, and the agent's default of 50 rounds is a long bill.
    private const int MAX_TOOL_CALLS = 30;

    /**
     * @param Closure(Checkout): ToolboxInterface $toolboxes
     */
    public function __construct(
        private ReviewerPrompt $prompt,
        #[AutowireCallable(service: ReviewerToolboxFactory::class, method: 'create')]
        private Closure $toolboxes,
        private VerdictParser $parser,
    ) {
    }

    #[Override]
    public function review(ReviewRequest $request, PlatformInterface $platform, string $model): Verdict
    {
        $tools = new MalformedToolCalls(($this->toolboxes)($request->checkout));

        $verdict = $this->answerOf($this->agent($tools, $platform, $model)->call(
            $this->prompt->messagesFor($request->rolePrompt, $request->task),
            [PlatformSubscriber::RESPONSE_FORMAT => Verdict::class],
        ));

        return $tools->seen() ? Verdict::invalidOutput('the reviewer made a malformed tool call.') : $verdict;
    }

    /**
     * @param non-empty-string $model
     */
    private function agent(ToolboxInterface $tools, PlatformInterface $platform, string $model): Agent
    {
        $toolbox = $this->prompt->fenced(new FaultTolerantToolbox($tools));

        return new Agent($platform, $model, name: 'reviewer', toolbox: $toolbox, maxToolCalls: self::MAX_TOOL_CALLS);
    }

    private function answerOf(ExecutionInterface $execution): Verdict
    {
        try {
            return $this->parser->parseWith(fn (): Verdict => $this->verdictOf($execution));
        } catch (MaxIterationsExceededException) {
            return Verdict::invalidOutput(sprintf('the reviewer was still asking for tools after %d calls.', self::MAX_TOOL_CALLS));
        }
    }

    private function verdictOf(ExecutionInterface $execution): Verdict
    {
        $answer = $execution->getContent();

        return $answer instanceof Verdict ? $answer : throw new UnexpectedResultTypeException(Verdict::class, get_debug_type($answer));
    }
}
