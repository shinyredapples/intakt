<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

// The verdicts as the reviewers gave them; whether they still stand for the current head is the standing rule's to say, which needs the forge.
#[McpTool(
    name: 'review_verdicts',
    description: 'Read the verdicts the reviewers of a round have given so far, each with its tier, decision and findings.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false),
)]
#[IsGrantedTool(ToolAccess::Read->value)]
final readonly class RoundVerdictsTool
{
    public function __construct(
        private ReadableRounds $rounds,
        private StoredVerdicts $verdicts,
    ) {
    }

    public function __invoke(#[Schema(description: 'UUID of the review round.')] string $roundId): RoundVerdicts
    {
        $round = $this->rounds->forTool($roundId);

        return new RoundVerdicts($round->id()->toRfc4122(), array_map(GivenVerdict::of(...), $this->verdicts->of($round)), $round->replayMark());
    }
}
