<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Mcp\Exception\RequestException;
use Mcp\Schema\JsonRpc\Error;
use Override;
use Symfony\AI\Agent\Bridge\Mcp\Exception\ToolErrorException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\McpBundle\Exception\RemoteCallException;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Uid\Uuid;
use Throwable;

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

    // Only a tool error or a refused call stays with the model, because the client turns an HTTP failure or an unreadable body into a protocol error too, and the tool list stays cached after the server went away, so the model would otherwise judge without reading.
    private function answeredByTheServer(ToolExecutionException $exception): bool
    {
        $failure = $exception->getPrevious();

        return $failure instanceof ToolErrorException || $this->refusedByTheServer($failure);
    }

    // The server answers a call it cannot serve (an unknown tool, invalid arguments) with invalid params; every other code is a failure of the server or of the way to it.
    private function refusedByTheServer(?Throwable $failure): bool
    {
        $request = $failure instanceof RemoteCallException ? $failure->getPrevious() : null;

        return $request instanceof RequestException && Error::INVALID_PARAMS === $request->getError()?->code;
    }
}
