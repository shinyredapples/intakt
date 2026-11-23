<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HighlightStatuses::class)]
final class HighlightStatusesTest extends TestCase
{
    public function testARowWhoseNamedIssuesAndPullRequestsAreAllClosedIsShownAsDone(): void
    {
        self::assertSame("## Tooling\n| Clock | what | done | T1 (#1, parts #2), `src/` |\n", $this->settle("## Tooling\n| Clock | what | in progress | T1 (#1, parts #2), `src/` |\n", [1 => false, 2 => false]));
    }

    public function testARowWithOneOpenNamedIssueIsShownAsInProgressEvenWhenWrittenDone(): void
    {
        self::assertSame('| Clock | what | in progress | #1 and #2 |', $this->settle('| Clock | what | done | #1 and #2 |', [1 => false, 2 => true]));
    }

    public function testARowWrittenPlannedStaysPlanned(): void
    {
        self::assertSame('| Clock | what | planned | #1 |', $this->settle('| Clock | what | planned | #1 |', [1 => false]));
    }

    public function testARowThatNamesNoIssueKeepsItsWrittenStatus(): void
    {
        self::assertSame('| Clock | what | in progress | `src/Clock/` |', $this->settle('| Clock | what | in progress | `src/Clock/` |', [1 => false]));
    }

    public function testAnUpstreamReferenceIsNotReadAsALocalIssue(): void
    {
        self::assertSame('| Clock | what | in progress | symfony/ai#2875 |', $this->settle('| Clock | what | in progress | symfony/ai#2875 |', [2875 => false]));
    }

    public function testANumberTheRecordingDoesNotHoldKeepsTheWrittenStatus(): void
    {
        self::assertSame('| Clock | what | in progress | #1, #9 |', $this->settle('| Clock | what | in progress | #1, #9 |', [1 => false]));
    }

    public function testARowWithOneOpenIssueAndANumberTheRecordingDoesNotHoldIsShownAsInProgress(): void
    {
        self::assertSame('| Clock | what | in progress | #14, #99 |', $this->settle('| Clock | what | done | #14, #99 |', [14 => true]));
    }

    public function testRowsInTheFoundInTheBetaTableAreNeverChanged(): void
    {
        $page = "## Found in the beta\n| Bug | in progress | T26 (#636) | filed |\n| Bug | what | in progress | #636 |\n";

        self::assertSame($page, $this->settle($page, [636 => false]));
    }

    public function testASectionAfterTheBetaTableIsSettledAgain(): void
    {
        self::assertStringContainsString('| Clock | what | done | #1 |', $this->settle("## Found in the beta\n\n## Tooling\n| Clock | what | in progress | #1 |\n", [1 => false]));
    }

    public function testARowWithAnotherNumberOfCellsIsLeftAlone(): void
    {
        self::assertSame('| Clock | in progress | #1 |', $this->settle('| Clock | in progress | #1 |', [1 => false]));
    }

    /**
     * @param array<int, bool> $open
     */
    private function settle(string $page, array $open): string
    {
        return new HighlightStatuses()->settled($page, $open);
    }
}
