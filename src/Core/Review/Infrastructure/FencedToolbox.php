<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\UntrustedSource;
use Override;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Agent\Toolbox\ToolResultConverter;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Every tool answer is repository text, which the code under review controls, so it reaches the model only inside a fence; excluded from the container because one is built per review, so its mark of a cut answer never outlives the review.
#[Exclude]
final class FencedToolbox implements ToolboxInterface
{
    private bool $cutAnAnswer = false;

    public function __construct(
        private readonly ToolboxInterface $inner,
        private readonly Fence $fence,
    ) {
    }

    #[Override]
    public function getTools(): array
    {
        return $this->inner->getTools();
    }

    #[Override]
    public function execute(ToolCall $toolCall): ToolResult
    {
        $result = $this->inner->execute($toolCall);
        $answer = new ToolResultConverter()->convert($result) ?? '';
        $cut = new PromptCut($this->fence);
        $this->cutAnAnswer = $cut->cuts($answer) || $this->cutAnAnswer;

        return new ToolResult($toolCall, $cut->fenced(UntrustedSource::ToolResult, $answer), $result->getSources());
    }

    public function cutAnAnswer(): bool
    {
        return $this->cutAnAnswer;
    }
}
