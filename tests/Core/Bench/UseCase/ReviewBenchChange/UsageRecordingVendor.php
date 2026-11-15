<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\UseCase\ReviewBenchChange;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Override;
use Symfony\Component\Clock\MockClock;

// Records the usage rows the real vendor records, named by reviewer, and takes the seconds its role is given, so the figures a bench reads back are known.
final readonly class UsageRecordingVendor implements Vendor
{
    /**
     * @param array<string, array{seconds: int, tokens: int}> $byRole
     */
    public function __construct(private VerdictPerRole $verdicts, private Ledger $ledger, private MockClock $clock, private array $byRole)
    {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        $role = $request->conversation->rolePrompt;
        $call = 'call-'.$role;
        $this->clock->sleep($this->byRole[$role]['seconds']);
        $this->append(['call' => $call, 'phase' => 'started', 'project' => $project, 'tier' => $tier->value, 'round' => $request->conversation->round?->toRfc4122(), 'model' => 'big', 'reviewer' => $request->conversation->reviewerId?->toRfc4122()]);
        $tokens = $this->byRole[$role]['tokens'];
        $this->append(['call' => $call, 'phase' => 'settled', 'usages' => [['account' => null, 'model' => null, 'prompt' => $tokens, 'completion' => 10, 'total' => $tokens + 10]]]);

        return $this->verdicts->review($project, $tier, $request);
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        return Judgement::everyBlockingFindingActs($request->findings);
    }

    #[Override]
    public function mapsAModelTo(string $project, Tier $tier): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(array $payload): void
    {
        (void) $this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, json_encode($payload, JSON_THROW_ON_ERROR)));
    }
}
