<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

#[McpTool(
    name: 'review_status',
    description: 'Read where a review round stands: its state (collecting, complete, judged), the state of each reviewer, and the findings dropped before the judge with the reason for each (empty until the round is judged).',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false),
)]
#[IsGrantedTool(ToolAccess::Read->value)]
final readonly class RoundStatusTool
{
    public function __construct(
        private ReadableRounds $rounds,
        private RoundLedgerReading $ledger,
    ) {
    }

    public function __invoke(#[Schema(description: 'UUID of the review round.')] string $roundId): RoundStatus
    {
        $round = $this->rounds->forTool($roundId);

        return RoundStatus::of($round, $this->droppedOf($round));
    }

    /**
     * @return list<DroppedFinding>
     */
    // Only a judged round, because the drops are recorded with the judge's answer.
    private function droppedOf(ReviewRound $round): array
    {
        if (RoundState::Judged !== $round->state()) {
            return [];
        }

        return $this->ledger->droppedOf($round->id()->toRfc4122());
    }
}
