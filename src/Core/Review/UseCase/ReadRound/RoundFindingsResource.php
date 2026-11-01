<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

// The text of the findings comes from code under review, so a client treats it as data and never as instructions.
#[McpResourceTemplate(
    uriTemplate: 'intakt://rounds/{roundId}/findings',
    name: 'round_findings',
    description: 'The findings a review round reported, under the ids the judge saw.',
    mimeType: 'application/json',
)]
#[IsGrantedTool(ToolAccess::Read->value)]
final readonly class RoundFindingsResource
{
    public function __construct(
        private ReadableRounds $rounds,
        private StoredFindings $findings,
    ) {
    }

    public function __invoke(string $roundId, string $uri): string
    {
        $round = $this->rounds->forResource($roundId, $uri);

        return json_encode($this->findings->reportedIn($round), JSON_THROW_ON_ERROR);
    }
}
