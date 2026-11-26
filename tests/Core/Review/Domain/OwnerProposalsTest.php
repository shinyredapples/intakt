<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\OwnerProposals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OwnerProposals::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(FindingIds::class)]
final class OwnerProposalsTest extends TestCase
{
    public function testAProposalNeverSendsTheChangeBack(): void
    {
        $verdict = $this->verdictWith($this->finding(Severity::P1, 'Proposal: docs gate limits'));

        self::assertSame(Severity::P3, $this->applied($verdict)->findings[0]->severity);
    }

    public function testAProposalIsTheOwnersToDecide(): void
    {
        $finding = new Finding('F1', Severity::P2, 'docs/user/cart.md', 3, null, 'Split the chapter.', FixableBy::Builder, false, 'Proposal: user guide structure', null);

        self::assertTrue($this->applied($this->verdictWith($finding))->findings[0]->proposesToOwner());
    }

    public function testAProposalIsReadWhateverItsCaseAndLeadingSpace(): void
    {
        $verdict = $this->verdictWith($this->finding(Severity::P2, '  proposal: user guide structure'));

        self::assertSame(Severity::P3, $this->applied($verdict)->findings[0]->severity);
    }

    public function testAFindingOnAPageKeepsItsSeverity(): void
    {
        $verdict = $this->verdictWith($this->finding(Severity::P2, 'Short paragraphs'));

        self::assertSame(Severity::P2, $this->applied($verdict)->findings[0]->severity);
    }

    public function testAFindingThatOnlyMentionsAProposalKeepsItsSeverity(): void
    {
        $verdict = $this->verdictWith($this->finding(Severity::P2, 'Right place (not a Proposal: here)'));

        self::assertSame(Severity::P2, $this->applied($verdict)->findings[0]->severity);
    }

    public function testAFindingWithoutARequirementKeepsItsSeverity(): void
    {
        $verdict = $this->verdictWith($this->finding(Severity::P1, null));

        self::assertSame(Severity::P1, $this->applied($verdict)->findings[0]->severity);
    }

    private function finding(Severity $severity, ?string $requirement): Finding
    {
        return new Finding('F1', $severity, 'docs/user/cart.md', 3, null, 'The chapter has no scene.', FixableBy::Owner, false, $requirement, null);
    }

    private function verdictWith(Finding $finding): Verdict
    {
        return new Verdict(Decision::Changes, [$finding], [], []);
    }

    private function applied(Verdict $verdict): Verdict
    {
        return new OwnerProposals()->applyToVerdict($verdict);
    }
}
