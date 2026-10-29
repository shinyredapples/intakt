<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Override;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;

final readonly class ReviewerAgent implements Reviewer
{
    // Because a reviewer that keeps asking for tools never answers: a review reads a handful of files, and the agent's default of 50 rounds is a long bill.
    private const int MAX_TOOL_CALLS = 30;

    public function __construct(
        private ReviewerPrompt $prompt,
        private ReviewerToolboxFactory $toolboxes,
        private VerdictParser $parser,
        private Fence $fence,
    ) {
    }

    #[Override]
    public function review(ReviewRequest $request, PlatformInterface $platform, string $model): Verdict
    {
        $execution = $this->agent($request, $platform, $model)->call(
            $this->prompt->messagesFor($request->rolePrompt, $request->task),
            [PlatformSubscriber::RESPONSE_FORMAT => Verdict::class],
        );

        try {
            return $this->parser->parseWith(fn (): Verdict => $this->verdictOf($execution));
        } catch (MaxIterationsExceededException) {
            return Verdict::invalidOutput(sprintf('the reviewer was still asking for tools after %d calls.', self::MAX_TOOL_CALLS));
        }
    }

    private function agent(ReviewRequest $request, PlatformInterface $platform, string $model): Agent
    {
        $toolbox = new FencedToolbox($this->toolboxes->create($request->checkout), $this->fence);

        return new Agent($platform, $model, name: 'reviewer', toolbox: $toolbox, maxToolCalls: self::MAX_TOOL_CALLS);
    }

    private function verdictOf(ExecutionInterface $execution): Verdict
    {
        $answer = $execution->getContent();

        return $answer instanceof Verdict ? $answer : throw new UnexpectedResultTypeException(Verdict::class, get_debug_type($answer));
    }
}
