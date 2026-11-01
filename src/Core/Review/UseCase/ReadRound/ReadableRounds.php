<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\RoundNotFound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ToolCallException;

// A round that does not exist is the client's mistake, so each door of the server reports it in the form the SDK sends as a clean MCP error.
final readonly class ReadableRounds
{
    public function __construct(private ReviewRounds $rounds)
    {
    }

    public function forTool(string $roundId): ReviewRound
    {
        try {
            return $this->rounds->stored($roundId);
        } catch (RoundNotFound $notFound) {
            throw new ToolCallException($notFound->getMessage(), previous: $notFound);
        }
    }

    public function forResource(string $roundId, string $uri): ReviewRound
    {
        try {
            return $this->rounds->stored($roundId);
        } catch (RoundNotFound $notFound) {
            throw new ResourceNotFoundException($uri);
        }
    }
}
