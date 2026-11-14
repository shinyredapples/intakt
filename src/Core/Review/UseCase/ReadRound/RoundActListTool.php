<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

#[McpTool(
    name: 'review_act_list',
    description: 'Read the act list of a round: the findings for the builder to fix (every P1 and P2 finding and the P3 findings the judge marked act). Empty and not judged until the judge has answered.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false),
)]
#[IsGrantedTool(ToolAccess::Read->value)]
final readonly class RoundActListTool
{
    public function __construct(
        private ReadableRounds $rounds,
        private StoredFindings $findings,
        private RoundLedgerReading $ledger,
    ) {
    }

    public function __invoke(#[Schema(description: 'UUID of the review round.')] string $roundId): RoundActList
    {
        $round = $this->rounds->forTool($roundId);
        $judged = RoundState::Judged === $round->state();

        return new RoundActList($round->id()->toRfc4122(), $judged, $judged ? $this->actOf($round) : []);
    }

    /**
     * @return list<Finding>
     */
    private function actOf(ReviewRound $round): array
    {
        $act = $this->ledger->actIdsOf($round->id()->toRfc4122());

        return $this->findings->actListOf($round, $act);
    }
}
