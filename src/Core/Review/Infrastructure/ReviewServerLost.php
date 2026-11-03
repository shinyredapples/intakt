<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Exception;
use Symfony\AI\Platform\Result\ToolCall;
use Throwable;

// Neither a tool execution failure nor a RuntimeException, because the fault-tolerant toolbox would hand the first to the model as a tool result and the verdict parser would read the second as invalid output.
final class ReviewServerLost extends Exception
{
    public static function during(ToolCall $toolCall, Throwable $previous): self
    {
        return new self(sprintf('The review server could not be reached for the tool call "%s".', $toolCall->getName()), previous: $previous);
    }
}
