<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\HighlightAssembly;
use Intakt\Gates\HighlightFragment;
use Intakt\Gates\HighlightFragments;
use Intakt\Gates\HighlightHistory;
use Intakt\Gates\HighlightLayout;
use Intakt\Gates\HighlightOrder;
use Intakt\Gates\HighlightRowKind;
use Intakt\Gates\RepositoryAttributesRefused;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(HighlightFragments::class)]
#[CoversClass(HighlightHistory::class)]
#[CoversClass(HighlightOrder::class)]
#[UsesClass(HighlightAssembly::class)]
#[UsesClass(HighlightRowKind::class)]
#[CoversClass(HighlightFragment::class)]
#[CoversClass(HighlightLayout::class)]
final class HighlightFragmentsTest extends SandboxTestCase
{
    private const string LAYOUT = "# Highlights\n\nIntro.\n\n## Tooling\n\n| Feature | Status | Where |\n|---|---|---|\n\n## PHP 8.5 in use\n";

    private const string UNORDERED_CLOCK = 'docs/highlights.d/2-earlier.md and docs/highlights.d/10-later.md both set row "Clock" of section "Tooling", but git history does not say which came first: add them in separate commits, or assemble in a clone with the full history';

    private Sandbox $sandbox;

    public function testRowsGoUnderTheTableHeaderAndItemsUnderTheirHeading(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tooling\n\n| Gates | done | T1 (#5) |\n\n## PHP 8.5 in use\n\n- The pipe operator: T6\n");

        $assembly = $this->assembled();

        self::assertSame([], $assembly->findings);
        self::assertSame(
            "# Highlights\n\nIntro.\n\n## Tooling\n\n| Feature | Status | Where |\n|---|---|---|\n| Gates | done | T1 (#5) |\n\n## PHP 8.5 in use\n\n- The pipe operator: T6\n",
            $assembly->text,
        );
    }

    public function testFragmentsApplyInTheNaturalOrderOfTheirNumbers(): void
    {
        $this->sandbox->write('docs/highlights.d/10-later.md', "## Tooling\n| Later | planned | T10 (#10) |\n");
        $this->sandbox->write('docs/highlights.d/2-earlier.md', "## Tooling\n| Earlier | planned | T2 (#2) |\n");

        self::assertStringContainsString("| Earlier | planned | T2 (#2) |\n| Later | planned | T10 (#10) |\n", $this->assembled()->text);
    }

    public function testALaterRowWithTheSameFirstCellReplacesTheEarlierOneInPlace(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1-first.md', "## Tooling\n| Gates | planned | T1 (#1) |\n| Clock | planned | T2 (#2) |\n");
        $this->commitFragment('3-update.md', "## Tooling\n|  Gates | done | T1 (#3) |\n");

        self::assertStringContainsString("|---|---|---|\n|  Gates | done | T1 (#3) |\n| Clock | planned | T2 (#2) |\n\n", $this->assembled()->text);
    }

    public function testALowerNumberedFragmentCommittedLaterReplacesTheRowOfAnEarlierCommittedOne(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1050-clock.md', "## Tooling\n| Clock | planned | T2 (#1050) |\n");
        $this->commitFragment('900-clock-done.md', "## Tooling\n| Clock | done | T2 (#900) |\n");

        $assembly = $this->assembled();

        self::assertStringContainsString("|---|---|---|\n| Clock | done | T2 (#900) |\n\n", $assembly->text);
        self::assertStringNotContainsString('planned', $assembly->text);
    }

    public function testAHigherNumberedFragmentCommittedLaterReplacesTheRowOfAnEarlierCommittedOne(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('900-clock.md', "## Tooling\n| Clock | planned | T2 (#900) |\n");
        $this->commitFragment('1050-clock-done.md', "## Tooling\n| Clock | done | T2 (#1050) |\n");

        self::assertStringContainsString("|---|---|---|\n| Clock | done | T2 (#1050) |\n\n", $this->assembled()->text);
    }

    public function testAFragmentAppliesInTheOrderItsBranchWasMergedNotTheOrderItWasWritten(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1-gates.md', "## Tooling\n| Gates | done | T1 (#1) |\n");
        $this->sandbox->git('checkout', '--quiet', '-b', 'older-issue');
        $this->commitFragment('900-clock-done.md', "## Tooling\n| Clock | done | T2 (#900) |\n");
        $this->sandbox->git('checkout', '--quiet', 'main');
        $this->commitFragment('1050-clock.md', "## Tooling\n| Clock | planned | T2 (#1050) |\n");
        $this->sandbox->git('merge', '--quiet', '--no-ff', '--no-edit', 'older-issue');

        self::assertStringContainsString("| Gates | done | T1 (#1) |\n| Clock | done | T2 (#900) |\n\n", $this->assembled()->text);
    }

    public function testAnUncommittedFragmentAppliesAfterTheCommittedOnes(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1050-clock.md', "## Tooling\n| Clock | planned | T2 (#1050) |\n");
        $this->sandbox->write('docs/highlights.d/900-clock-done.md', "## Tooling\n| Clock | done | T2 (#900) |\n");

        self::assertStringContainsString("|---|---|---|\n| Clock | done | T2 (#900) |\n\n", $this->assembled()->text);
    }

    public function testFragmentsAMergeOfMainBringsInApplyInTheOrderTheyWereMergedIntoMain(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1-gates.md', "## Tooling\n| Gates | done | T1 (#1) |\n");
        $this->sandbox->git('branch', 'train');
        $this->mergeFragment('2832-lock.md', "## Tooling\n| Lock | planned | T2 (#2832) |\n");
        $this->mergeFragment('2022-lock-done.md', "## Tooling\n| Lock | done | T2 (#2022) |\n");
        $this->sandbox->git('checkout', '--quiet', 'train');
        $this->sandbox->git('merge', '--quiet', '--no-ff', '--no-edit', 'main');
        $this->sandbox->git('checkout', '--quiet', 'main');
        $this->sandbox->git('merge', '--quiet', '--ff-only', 'train');

        $assembly = $this->assembled();

        self::assertSame([], $assembly->findings);
        self::assertStringContainsString("| Gates | done | T1 (#1) |\n| Lock | done | T2 (#2022) |\n\n", $assembly->text);
    }

    public function testAFragmentAMergeRestoresAfterTheMergedSideDeletedItAppliesAfterTheRowsOfThatSide(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1-gates.md', "## Tooling\n| Gates | done | T1 (#1) |\n");
        $this->sandbox->git('checkout', '--quiet', '-b', 'side');
        $this->commitFragment('2832-restored.md', "## Tooling\n| Clock | restored by merge | T2 (#2832) |\n");
        $this->sandbox->git('rm', '--quiet', 'docs/highlights.d/2832-restored.md');
        $this->commitFragment('2022-side.md', "## Tooling\n| Clock | from side | T2 (#2022) |\n");
        $this->sandbox->git('checkout', '--quiet', 'main');
        $this->sandbox->git('merge', '--quiet', '--no-ff', '--no-commit', 'side');
        $this->commitFragment('2832-restored.md', "## Tooling\n| Clock | restored by merge | T2 (#2832) |\n");

        $assembly = $this->assembled();

        self::assertSame([], $assembly->findings);
        self::assertStringContainsString("| Gates | done | T1 (#1) |\n| Clock | restored by merge | T2 (#2832) |\n\n", $assembly->text);
    }

    public function testFragmentsFromTwoMergedParentsOfOneMergeThatSetTheSameRowFailTheAssembly(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1-gates.md', "## Tooling\n| Gates | done | T1 (#1) |\n");
        $this->sandbox->git('branch', 'right');
        $this->sandbox->git('checkout', '--quiet', '-b', 'left');
        $this->commitFragment('2-earlier.md', "## Tooling\n| Clock | planned | T2 (#2) |\n");
        $this->sandbox->git('checkout', '--quiet', 'right');
        $this->commitFragment('10-later.md', "## Tooling\n| Clock | done | T10 (#10) |\n");
        $this->sandbox->git('checkout', '--quiet', 'main');
        $this->sandbox->git('merge', '--quiet', '--no-ff', '--no-edit', 'left', 'right');

        $assembly = $this->assembled();

        self::assertSame('', $assembly->text);
        self::assertSame([self::UNORDERED_CLOCK], $assembly->findings);
    }

    public function testFragmentsFromTwoMergedParentsOfOneMergeWhoseRowsDoNotOverlapAssemble(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1-gates.md', "## Tooling\n| Gates | done | T1 (#1) |\n");
        $this->sandbox->git('branch', 'right');
        $this->sandbox->git('checkout', '--quiet', '-b', 'left');
        $this->commitFragment('2-earlier.md', "## Tooling\n| Earlier | planned | T2 (#2) |\n");
        $this->sandbox->git('checkout', '--quiet', 'right');
        $this->commitFragment('10-later.md', "## Tooling\n| Later | planned | T10 (#10) |\n");
        $this->sandbox->git('checkout', '--quiet', 'main');
        $this->sandbox->git('merge', '--quiet', '--no-ff', '--no-edit', 'left', 'right');

        $assembly = $this->assembled();

        self::assertSame([], $assembly->findings);
        self::assertStringContainsString("| Gates | done | T1 (#1) |\n| Earlier | planned | T2 (#2) |\n| Later | planned | T10 (#10) |\n", $assembly->text);
    }

    public function testFragmentsOneMergedParentOfAnOctopusMergeAddedInTurnApplyInThatParentsOrder(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1-gates.md', "## Tooling\n| Gates | done | T1 (#1) |\n");
        $this->sandbox->git('branch', 'right');
        $this->sandbox->git('checkout', '--quiet', '-b', 'left');
        $this->commitFragment('90-lock.md', "## Tooling\n| Lock | planned | T2 (#90) |\n");
        $this->commitFragment('20-lock-done.md', "## Tooling\n| Lock | done | T2 (#20) |\n");
        $this->sandbox->git('checkout', '--quiet', 'right');
        $this->commitFragment('30-clock.md', "## Tooling\n| Clock | done | T3 (#30) |\n");
        $this->sandbox->git('checkout', '--quiet', 'main');
        $this->sandbox->git('merge', '--quiet', '--no-ff', '--no-edit', 'left', 'right');

        $assembly = $this->assembled();

        self::assertSame([], $assembly->findings);
        self::assertStringContainsString("| Gates | done | T1 (#1) |\n| Lock | done | T2 (#20) |\n| Clock | done | T3 (#30) |\n", $assembly->text);
    }

    public function testAFragmentAnOctopusMergeAddsItselfAppliesAfterTheRowsOfEveryMergedParent(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1-gates.md', "## Tooling\n| Gates | done | T1 (#1) |\n");
        $this->sandbox->git('branch', 'right');
        $this->sandbox->git('checkout', '--quiet', '-b', 'left');
        $this->commitFragment('900-lock.md', "## Tooling\n| Lock | planned | T2 (#900) |\n");
        $this->sandbox->git('checkout', '--quiet', 'right');
        $this->commitFragment('800-clock.md', "## Tooling\n| Clock | planned | T3 (#800) |\n");
        $this->sandbox->git('checkout', '--quiet', 'main');
        $this->sandbox->git('merge', '--quiet', '--no-ff', '--no-commit', 'left', 'right');
        $this->commitFragment('5-both-done.md', "## Tooling\n| Lock | done | T2 (#5) |\n| Clock | done | T3 (#5) |\n");

        $assembly = $this->assembled();

        self::assertSame([], $assembly->findings);
        self::assertStringContainsString("| Gates | done | T1 (#1) |\n| Lock | done | T2 (#5) |\n| Clock | done | T3 (#5) |\n", $assembly->text);
    }

    public function testTwoUncommittedFragmentsThatSetTheSameRowFailTheAssembly(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1-gates.md', "## Tooling\n| Gates | done | T1 (#1) |\n");
        $this->sandbox->write('docs/highlights.d/10-later.md', "## Tooling\n| Clock | done | T10 (#10) |\n");
        $this->sandbox->write('docs/highlights.d/2-earlier.md', "## Tooling\n| Clock | planned | T2 (#2) |\n");

        self::assertSame([self::UNORDERED_CLOCK], $this->assembled()->findings);
    }

    public function testARepositoryWithoutCommitsRefusesFragmentsThatSetTheSameRow(): void
    {
        $this->sandbox->startRepository();
        $this->sandbox->write('docs/highlights.d/10-later.md', "## Tooling\n| Clock | done | T10 (#10) |\n");
        $this->sandbox->write('docs/highlights.d/2-earlier.md', "## Tooling\n| Clock | planned | T2 (#2) |\n");

        $assembly = $this->assembled();

        self::assertSame('', $assembly->text);
        self::assertSame([self::UNORDERED_CLOCK], $assembly->findings);
    }

    public function testASourceSnapshotWithoutGitHistoryRefusesFragmentsThatSetTheSameRow(): void
    {
        $this->sandbox->write('docs/highlights.d/10-later.md', "## PHP 8.5 in use\n- The pipe operator: `a.php` (done, T1).\n");
        $this->sandbox->write('docs/highlights.d/2-earlier.md', "## PHP 8.5 in use\n- The pipe operator: `a.php` (planned).\n");

        self::assertSame(
            ['docs/highlights.d/2-earlier.md and docs/highlights.d/10-later.md both set row "- The pipe operator: `a.php`" of section "PHP 8.5 in use", but git history does not say which came first: add them in separate commits, or assemble in a clone with the full history'],
            $this->assembled()->findings,
        );
    }

    public function testAHistoryTheHardenedGitCallRefusesStopsTheAssembly(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1-gates.md', "## Tooling\n| Gates | done | T1 (#1) |\n");
        $this->sandbox->write('.git/info/attributes', "* diff=planted\n");

        $this->expectException(RepositoryAttributesRefused::class);

        $this->assembled();
    }

    public function testAHistoryGitCannotReadFailsTheAssembly(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1-gates.md', "## Tooling\n| Gates | done | T1 (#1) |\n");
        $this->commitFragment('2-clock.md', "## Tooling\n| Clock | done | T2 (#2) |\n");
        $tree = trim($this->sandbox->git('rev-parse', 'HEAD~1^{tree}')->output);
        unlink($this->sandbox->root.'/.git/objects/'.substr($tree, 0, 2).'/'.substr($tree, 2));

        $assembly = $this->assembled();

        self::assertSame('', $assembly->text);
        self::assertStringStartsWith('cannot read the history of docs/highlights.d/: ', $assembly->findings[0] ?? '');
    }

    public function testAHeadGitCannotReadFailsTheAssemblyInsteadOfChangingItsOrder(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1050-clock.md', "## Tooling\n| Clock | planned | T2 (#1050) |\n");
        $this->commitFragment('900-clock-done.md', "## Tooling\n| Clock | done | T2 (#900) |\n");
        $this->sandbox->write('.git/HEAD', str_repeat('1', 40)."\n");

        $assembly = $this->assembled();

        self::assertSame('', $assembly->text);
        self::assertStringStartsWith('cannot read the history of docs/highlights.d/: ', $assembly->findings[0] ?? '');
    }

    public function testAHeadGitCannotParseFailsTheAssemblyInsteadOfChangingItsOrder(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('100-first.md', "## Tooling\n| Clock | planned | T1 (#100) |\n");
        $this->commitFragment('2-later.md', "## Tooling\n| Clock | done | T2 (#2) |\n");
        $this->sandbox->write('.git/HEAD', "invalid HEAD\n");

        $assembly = $this->assembled();

        self::assertSame('', $assembly->text);
        self::assertStringStartsWith('cannot read the history of docs/highlights.d/: ', $assembly->findings[0] ?? '');
    }

    public function testAShallowHistoryFailsTheAssemblyInsteadOfChangingItsOrder(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('1050-clock.md', "## Tooling\n| Clock | planned | T2 (#1050) |\n");
        $this->commitFragment('900-clock-done.md', "## Tooling\n| Clock | done | T2 (#900) |\n");
        $this->sandbox->write('.git/shallow', trim($this->sandbox->git('rev-parse', 'HEAD')->output)."\n");

        $assembly = $this->assembled();

        self::assertSame('', $assembly->text);
        self::assertSame(['the history of docs/highlights.d/ is shallow, so the order its fragments were merged in is unknown: fetch the full history (git fetch --unshallow)'], $assembly->findings);
    }

    public function testTwoFragmentsAddedInOnePlainCommitThatSetTheSameRowFailInsteadOfApplyingByNumber(): void
    {
        $this->sandbox->startRepository();
        $this->sandbox->write('docs/highlights.d/10-later.md', "## Tooling\n| Clock | done | T10 (#10) |\n");
        $this->commitFragment('2-earlier.md', "## Tooling\n| Clock | planned | T2 (#2) |\n");

        self::assertSame([self::UNORDERED_CLOCK], $this->assembled()->findings);
    }

    public function testFragmentsAddedInOneCommitWhoseRowsDoNotOverlapApplyInTheNaturalOrderOfTheirNumbers(): void
    {
        $this->sandbox->startRepository();
        $this->sandbox->write('docs/highlights.d/10-later.md', "## Tooling\n| Later | planned | T10 (#10) |\n");
        $this->commitFragment('2-earlier.md', "## Tooling\n| Earlier | planned | T2 (#2) |\n");

        $assembly = $this->assembled();

        self::assertSame([], $assembly->findings);
        self::assertStringContainsString("| Earlier | planned | T2 (#2) |\n| Later | planned | T10 (#10) |\n", $assembly->text);
    }

    public function testALaterListItemWithTheSameTextBeforeItsStatusReplacesTheEarlierOneInPlace(): void
    {
        $this->sandbox->startRepository();
        $this->commitFragment('0-before.md', "## PHP 8.5 in use\n- The pipe operator: `a.php` (in progress, T1).\n- Clone with: `b.php` (in progress, T8 (#13, part #222)).\n");
        $this->commitFragment('5-done.md', "## PHP 8.5 in use\n- Clone with: `b.php` (done, T8 (#13, part #222)).\n- The pipe operator: `a.php` (done, T1).\n");

        self::assertStringEndsWith(
            "## PHP 8.5 in use\n\n- The pipe operator: `a.php` (done, T1).\n- Clone with: `b.php` (done, T8 (#13, part #222)).\n",
            $this->assembled()->text,
        );
    }

    public function testAListItemWithOtherTextBeforeItsStatusIsAddedNextToTheEarlierOne(): void
    {
        $this->sandbox->write('docs/highlights.d/0-before.md', "## PHP 8.5 in use\n- The pipe operator: `a.php` (done, T1).\n");
        $this->sandbox->write('docs/highlights.d/5-more.md', "## PHP 8.5 in use\n- The pipe operator: `b.php` (done, T1).\n");

        self::assertStringEndsWith("- The pipe operator: `a.php` (done, T1).\n- The pipe operator: `b.php` (done, T1).\n", $this->assembled()->text);
    }

    public function testAListItemWithoutATrailingStatusIsKeyedByItsWholeLine(): void
    {
        $this->sandbox->write('docs/highlights.d/0-before.md', "## PHP 8.5 in use\n- Planned: the pipe operator (T5).\n- `#[NoDiscard]` (done) on the status.\n");
        $this->sandbox->write('docs/highlights.d/5-more.md', "## PHP 8.5 in use\n- Planned: the pipe operator (T6).\n- `#[NoDiscard]` (planned) on the status.\n");

        self::assertStringEndsWith(
            "- Planned: the pipe operator (T5).\n- `#[NoDiscard]` (done) on the status.\n- Planned: the pipe operator (T6).\n- `#[NoDiscard]` (planned) on the status.\n",
            $this->assembled()->text,
        );
    }

    public function testTheSameListItemTwiceInOneFragmentFailsWhateverItsStatus(): void
    {
        $this->sandbox->write('docs/highlights.d/1-pipe.md', "## PHP 8.5 in use\n- The pipe operator: `a.php` (planned).\n- The pipe operator: `a.php` (done, T1).\n");

        self::assertSame(['docs/highlights.d/1-pipe.md:3: row "- The pipe operator: `a.php`" appears twice in this fragment'], $this->assembled()->findings);
    }

    public function testASectionWithoutRowsKeepsItsHeader(): void
    {
        self::assertSame(
            "# Highlights\n\nIntro.\n\n## Tooling\n\n| Feature | Status | Where |\n|---|---|---|\n\n## PHP 8.5 in use\n",
            $this->assembled()->text,
        );
    }

    public function testASectionThatIsNotInTheLayoutFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tools\n| Gates | done | T1 (#5) |\n");

        self::assertSame(['docs/highlights.d/1-gates.md:1: section "Tools" is not a section of docs/highlights.d/layout.md'], $this->assembled()->findings);
    }

    public function testALineBeforeTheFirstSectionHeadingFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "| Gates | done | T1 (#5) |\n");

        self::assertSame(['docs/highlights.d/1-gates.md:1: the line is outside a section, start the fragment with a "## <section>" heading of docs/highlights.d/layout.md'], $this->assembled()->findings);
    }

    public function testAListItemInATableSectionFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tooling\n- Gates\n");

        self::assertSame(['docs/highlights.d/1-gates.md:2: section "Tooling" holds table rows, each starting with "|"'], $this->assembled()->findings);
    }

    public function testATableRowInAListSectionFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-pipe.md', "## PHP 8.5 in use\n| Pipe | done | T6 |\n");

        self::assertSame(['docs/highlights.d/1-pipe.md:2: section "PHP 8.5 in use" holds list items, each starting with "- "'], $this->assembled()->findings);
    }

    public function testATableHeaderInAFragmentFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tooling\n|---|---|---|\n");

        self::assertSame(['docs/highlights.d/1-gates.md:2: a fragment holds rows only, the table header is in docs/highlights.d/layout.md'], $this->assembled()->findings);
    }

    public function testTheSameRowTwiceInOneFragmentFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tooling\n| Gates | planned | T1 (#5) |\n| Gates | done | T1 (#5) |\n");

        self::assertSame(['docs/highlights.d/1-gates.md:3: row "Gates" appears twice in this fragment'], $this->assembled()->findings);
    }

    public function testAFileNotNamedAfterAnIssueFails(): void
    {
        $this->sandbox->write('docs/highlights.d/gates.md', "## Tooling\n| Gates | done | T1 (#5) |\n");
        $this->sandbox->write('docs/highlights.d/1-Gates.md', "## Tooling\n| Gates | done | T1 (#5) |\n");

        self::assertSame(
            [
                'docs/highlights.d/1-Gates.md: a fragment is named <issue>-<slug>.md, the slug in lowercase letters, digits and hyphens',
                'docs/highlights.d/gates.md: a fragment is named <issue>-<slug>.md, the slug in lowercase letters, digits and hyphens',
            ],
            $this->assembled()->findings,
        );
    }

    public function testAMissingLayoutFails(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tooling\n| Gates | done | T1 (#5) |\n");

        self::assertSame(['cannot read docs/highlights.d/layout.md'], new HighlightFragments()->assembled($this->sandbox->root)->findings);
    }

    public function testThePageIsTheAssemblyEvenWhenALocalCopyIsStale(): void
    {
        $this->sandbox->write('docs/highlights.md', $this->assembled()->text);
        $this->sandbox->write('docs/highlights.d/1045-new-feature.md', "## Tooling\n| New feature | done | #1045 |\n");

        self::assertStringContainsString('| New feature | done | #1045 |', (string) new HighlightFragments()->pageOn($this->sandbox->root)?->text);
    }

    public function testBrokenFragmentsGiveAPageWithTheirFindings(): void
    {
        $this->sandbox->write('docs/highlights.d/1-gates.md', "## Tools\n| Gates | done | T1 (#5) |\n");
        $this->assembled();

        self::assertCount(1, new HighlightFragments()->pageOn($this->sandbox->root)->findings ?? []);
    }

    public function testWithoutFragmentsThePageIsTheFileItself(): void
    {
        $this->sandbox->write('docs/highlights.md', 'anything');

        self::assertSame('anything', new HighlightFragments()->pageOn($this->sandbox->root)?->text);
    }

    public function testWithoutFragmentsOrAFileThereIsNoPage(): void
    {
        self::assertNull(new HighlightFragments()->pageOn($this->sandbox->root));
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = $this->emptySandbox();
    }

    private function assembled(): HighlightAssembly
    {
        if (!is_file($this->sandbox->root.'/docs/highlights.d/layout.md')) {
            $this->sandbox->write('docs/highlights.d/layout.md', self::LAYOUT);
        }

        return new HighlightFragments()->assembled($this->sandbox->root);
    }

    private function commitFragment(string $name, string $rows): void
    {
        if (!is_file($this->sandbox->root.'/docs/highlights.d/layout.md')) {
            $this->sandbox->write('docs/highlights.d/layout.md', self::LAYOUT);
        }

        $this->sandbox->write('docs/highlights.d/'.$name, $rows);
        $this->sandbox->commitAll('docs(highlights): add '.$name);
    }

    private function mergeFragment(string $name, string $rows): void
    {
        $this->sandbox->git('checkout', '--quiet', '-b', 'issue-'.$name);
        $this->commitFragment($name, $rows);
        $this->sandbox->git('checkout', '--quiet', 'main');
        $this->sandbox->git('merge', '--quiet', '--no-ff', '--no-edit', 'issue-'.$name);
    }
}
