<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Override;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Uid\Uuid;

// Excluded from the container because ReviewerMcpToolboxFactory builds one per review, holding its round.
#[Exclude]
final readonly class ToolsOfRound implements ToolboxInterface
{
    public function __construct(
        private ToolboxInterface $tools,
        private ReviewServerHttpClient $http,
        private Uuid $round,
    ) {
    }

    #[Override]
    public function getTools(): array
    {
        return $this->http->during($this->round, $this->tools->getTools(...));
    }

    #[Override]
    public function execute(ToolCall $toolCall): ToolResult
    {
        return $this->http->during($this->round, fn (): ToolResult => $this->tools->execute($toolCall));
    }
}
