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

// Every tool answer is repository text, which the code under review controls, so it reaches the model only inside a fence.
final readonly class FencedToolbox implements ToolboxInterface
{
    public function __construct(
        private ToolboxInterface $inner,
        private Fence $fence,
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

        return new ToolResult($toolCall, $this->fence->wrap(UntrustedSource::ToolResult, $answer), $result->getSources());
    }
}
