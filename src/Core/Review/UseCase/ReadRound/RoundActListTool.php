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
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

#[McpTool(
    name: 'review_act_list',
    description: 'Read the act list of a round: the findings the judge marked for the builder to fix. Empty and not judged until the judge has answered.',
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

    public function __invoke(string $roundId): RoundActList
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

        return array_values(array_filter($this->findings->reportedIn($round), static fn (Finding $finding): bool => in_array($finding->id, $act, true)));
    }
}
