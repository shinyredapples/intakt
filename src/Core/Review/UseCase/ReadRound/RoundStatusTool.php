<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

#[McpTool(
    name: 'review_status',
    description: 'Read where a review round stands: its state (collecting, complete, judged) and the state of each reviewer.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false),
)]
#[IsGrantedTool(ToolAccess::Read->value)]
final readonly class RoundStatusTool
{
    public function __construct(private ReadableRounds $rounds)
    {
    }

    public function __invoke(#[Schema(description: 'UUID of the review round.')] string $roundId): RoundStatus
    {
        return RoundStatus::of($this->rounds->forTool($roundId));
    }
}
