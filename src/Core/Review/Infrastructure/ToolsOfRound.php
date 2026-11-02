<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Mcp\Exception\RequestException;
use Override;
use Symfony\AI\Agent\Bridge\Mcp\Exception\ToolErrorException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\McpBundle\Exception\RemoteCallException;
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
        try {
            return $this->http->during($this->round, fn (): ToolResult => $this->tools->execute($toolCall));
        } catch (ToolExecutionException $exception) {
            throw $this->answeredByTheServer($exception) ? $exception : ReviewServerLost::during($toolCall, $exception);
        }
    }

    // Everything but an answer of the server counts as lost, because the tool list stays cached after the server went away and the model would otherwise judge without reading.
    private function answeredByTheServer(ToolExecutionException $exception): bool
    {
        $failure = $exception->getPrevious();

        return $failure instanceof ToolErrorException
            || ($failure instanceof RemoteCallException && $failure->getPrevious() instanceof RequestException);
    }
}
