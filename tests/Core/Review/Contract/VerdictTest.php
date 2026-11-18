<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Evidence::class)]
final class VerdictTest extends TestCase
{
    public function testAnIncompleteStreamVerdictBlocksAndNamesTheReason(): void
    {
        $verdict = Verdict::incompleteStream('the stream ended early', new Evidence('the call to anthropic, model big', 'cut by: platform'));

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::INCOMPLETE_STREAM, $verdict->findings[0]->id);
        self::assertStringContainsString('the stream ended early', $verdict->findings[0]->text);
    }

    public function testAnIncompleteStreamVerdictKeepsTheEvidenceOfTheCut(): void
    {
        $evidence = new Evidence('the call to anthropic, model big', "cut by: platform\nchunks: 2");

        self::assertSame($evidence, Verdict::incompleteStream('the stream ended early', $evidence)->findings[0]->evidence);
    }

    public function testAnApprovalAndChangesOfOnlyP3FindingsMayCountAsApproval(): void
    {
        self::assertSame([true, true], [
            new Verdict(Decision::Approve, [], [], [])->mayCountAsApproval(),
            $this->changes(Severity::P3, Severity::P3)->mayCountAsApproval(),
        ]);
    }

    public function testChangesWithAP2WithoutFindingsOrOfAFailedReviewNeverCountAsApproval(): void
    {
        $failed = new Verdict(Decision::Changes, [new Finding(Verdict::NO_TOOLS, Severity::P3, null, null, null, 'No tools.', FixableBy::Owner, false, null, null)], [], []);

        self::assertSame([false, false, false, false], [
            $this->changes(Severity::P3, Severity::P2)->mayCountAsApproval(),
            $this->changes()->mayCountAsApproval(),
            $failed->mayCountAsApproval(),
            Verdict::unreadApproval()->mayCountAsApproval(),
        ]);
    }

    private function changes(Severity ...$severities): Verdict
    {
        return new Verdict(Decision::Changes, array_map(static fn (Severity $severity): Finding => new Finding('A', $severity, null, null, null, 'Text.', FixableBy::Builder, false, null, null), array_values($severities)), [], []);
    }
}
