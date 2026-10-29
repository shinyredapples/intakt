<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Override;
use Symfony\AI\Agent\Toolbox\Exception\InvalidToolCallArgumentsException;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The fault-tolerant toolbox answers a malformed call to the model and hides it from the reviewer, which must still end such a review as changes.
// Excluded from the container because one is built per review around that review's toolbox, so its mark never outlives the review.
#[Exclude]
final class MalformedToolCalls implements ToolboxInterface
{
    private bool $seen = false;

    public function __construct(private readonly ToolboxInterface $inner)
    {
    }

    #[Override]
    public function getTools(): array
    {
        return $this->inner->getTools();
    }

    #[Override]
    public function execute(ToolCall $toolCall): ToolResult
    {
        try {
            return $this->inner->execute($toolCall);
        } catch (InvalidToolCallArgumentsException|ToolNotFoundException $exception) {
            $this->seen = true;

            throw $exception;
        }
    }

    public function seen(): bool
    {
        return $this->seen;
    }
}
