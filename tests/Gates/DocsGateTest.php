<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\DocsAllowances;
use Intakt\Gates\DocsGate;
use Intakt\Gates\DocsRatchetList;
use Intakt\Gates\DocsTargetBranch;
use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\IndentedCodeBlocks;
use Intakt\Gates\MarkdownPage;
use Intakt\Gates\MarkdownParagraph;
use Intakt\Gates\UnneededDocsEntries;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(DocsGate::class)]
#[CoversClass(DocsRatchetList::class)]
#[CoversClass(MarkdownPage::class)]
#[CoversClass(MarkdownParagraph::class)]
#[CoversClass(IndentedCodeBlocks::class)]
#[CoversClass(UnneededDocsEntries::class)]
#[CoversClass(DocsAllowances::class)]
#[CoversClass(DocsTargetBranch::class)]
#[UsesClass(FencedCodeBlocks::class)]
#[UsesClass(Workspace::class)]
final class DocsGateTest extends SandboxTestCase
{
    public function testAShortPageWithShortParagraphsPasses(): void
    {
        $sandbox = $this->changeWith(['docs/user/start.md' => "# Start\n\n".$this->words(30)."\n\n1. ".$this->words(20)."\n"]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'), $sandbox->runGate(new DocsGate())->output());
    }

    public function testANewParagraphOverTheLimitFailsNamingTheFileLineAndLimit(): void
    {
        $sandbox = $this->changeWith(['docs/user/start.md' => "# Start\n\nShort.\n\n".$this->words(81)."\n"]);

        $run = $sandbox->runGate(new DocsGate());

        self::assertTrue($run->failed('docs'), $run->output());
        self::assertStringContainsString('docs/user/start.md:5: paragraph of 81 words, the limit is 80; split it', $run->findingsOf('docs'));
    }

    public function testAParagraphAtTheLimitPasses(): void
    {
        $sandbox = $this->changeWith(['docs/user/start.md' => "# Start\n\n".$this->words(80)."\n"]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testTheReadmeIsJudgedLikeAPageUnderDocs(): void
    {
        $sandbox = $this->changeWith(['README.md' => "# Intakt\n\n".$this->words(90)."\n"]);

        self::assertStringContainsString('README.md:3: paragraph of 90 words', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testMarkdownOutsideTheDocumentationIsNotJudged(): void
    {
        $sandbox = $this->changeWith(['notes/long.md' => $this->words(200)."\n"]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testEveryListItemIsItsOwnParagraph(): void
    {
        $sandbox = $this->changeWith(['docs/user/start.md' => "# Start\n\n".implode("\n", array_fill(0, 3, '- '.$this->words(50)))."\n"]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testTheLinesOfOneParagraphCountTogether(): void
    {
        $sandbox = $this->changeWith(['docs/user/start.md' => "# Start\n\n".$this->words(50)."\n".$this->words(50)."\n"]);

        self::assertStringContainsString('paragraph of 100 words', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testFencedCodeAndTablesAreNotParagraphs(): void
    {
        $table = '| '.$this->words(100)." |\n| --- |\n";
        $sandbox = $this->changeWith(['docs/user/start.md' => "# Start\n\n```\n".$this->words(100)."\n```\n\n".$table]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testATableWithoutOuterPipesIsNotAParagraph(): void
    {
        $sandbox = $this->changeWith(['docs/guide/page.md' => "One | Two\n--- | ---\n".$this->words(90)." | cell\n"]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testProseBeginningWithAPipeWithoutADelimiterRowIsAParagraph(): void
    {
        $sandbox = $this->changeWith(['docs/guide/page.md' => '| '.$this->words(81)."\n"]);

        self::assertStringContainsString('docs/guide/page.md:1: paragraph of 82 words', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testARowBetweenOuterPipesWithoutItsHeaderIsNotAParagraph(): void
    {
        $sandbox = $this->changeWith(['docs/highlights.d/1-row.md' => "## Section\n\n| ".$this->words(90)." | done |\n"]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testAnIndentedCodeBlockIsNotAParagraph(): void
    {
        $sandbox = $this->changeWith(['docs/guide/page.md' => "Code:\n\n    ".$this->words(90)."\n    ".$this->words(10)."\n"]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testIndentedTextInsideAListItemIsStillProse(): void
    {
        $sandbox = $this->changeWith(['docs/guide/page.md' => '- '.$this->words(5)."\n\n    ".$this->words(81)."\n"]);

        self::assertStringContainsString('docs/guide/page.md:3: paragraph of 81 words', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testBlockquoteMarkersAreNotCountedAsWords(): void
    {
        $sandbox = $this->changeWith(['docs/guide/page.md' => '> '.$this->words(40)."\n> ".$this->words(40)."\n"]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testAnUnchangedOversizedParagraphOnTheListPasses(): void
    {
        $paragraph = $this->words(120);
        $sandbox = $this->changeWith(['docs/user/start.md' => "# Start\n\n".$paragraph."\n", DocsRatchetList::PATH => $this->listOf(['docs/user/start.md' => [$paragraph]])]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testAnEditedOversizedParagraphOnTheListFails(): void
    {
        $paragraph = $this->words(120);
        $sandbox = $this->changeWith(['docs/user/start.md' => "# Start\n\n".$paragraph." and one more.\n", DocsRatchetList::PATH => $this->listOf(['docs/user/start.md' => [$paragraph]])]);

        self::assertTrue($sandbox->runGate(new DocsGate())->failed('docs'));
    }

    public function testACopyOfAListedParagraphInTheSamePageFails(): void
    {
        $paragraph = $this->words(81);
        $sandbox = $this->startedChange(['docs/user/start.md' => $paragraph."\n", DocsRatchetList::PATH => $this->listOf(['docs/user/start.md' => [$paragraph]])]);
        $sandbox->write('docs/user/start.md', $paragraph."\n\n".$paragraph."\n");
        $sandbox->commitAll('docs: copy the paragraph');

        self::assertStringContainsString('docs/user/start.md:3: paragraph of 81 words', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAListedKeyThatNoLongerMatchesAParagraphMustBeRemoved(): void
    {
        $paragraph = $this->words(81);
        $sandbox = $this->startedChange(['docs/user/start.md' => $paragraph."\n", DocsRatchetList::PATH => $this->listOf(['docs/user/start.md' => [$paragraph]])]);
        $sandbox->write('docs/user/start.md', "Short.\n");
        $sandbox->commitAll('docs: shorten the paragraph');

        self::assertStringContainsString('ratchet/docs.json: unneeded paragraph keys of docs/user/start.md: 1 match no oversized paragraph any more; remove them', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAnEntryAddedToTheListFails(): void
    {
        $paragraph = $this->words(120);
        $sandbox = $this->startedChange([DocsRatchetList::PATH => $this->listOf([])]);
        $sandbox->write('docs/user/start.md', "# Start\n\n".$paragraph."\n");
        $sandbox->write(DocsRatchetList::PATH, $this->listOf(['docs/user/start.md' => [$paragraph]]));
        $sandbox->commitAll('docs: list a paragraph');

        $run = $sandbox->runGate(new DocsGate());

        self::assertTrue($run->failed('docs'), $run->output());
        self::assertStringContainsString('ratchet/docs.json: 1 paragraph of docs/user/start.md added to the list', $run->findingsOf('docs'));
    }

    public function testAPageCeilingRaisedAboveTheTargetBranchFails(): void
    {
        $sandbox = $this->startedChange([DocsRatchetList::PATH => $this->listOf([], ['docs/guide/big.md' => 2000])]);
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([], ['docs/guide/big.md' => 2500]));
        $sandbox->commitAll('docs: raise a ceiling');

        self::assertStringContainsString('raised from 2000 to 2500', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAPageCeilingThatIsNewFails(): void
    {
        $sandbox = $this->startedChange([DocsRatchetList::PATH => $this->listOf([])]);
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([], ['docs/guide/big.md' => 2000]));
        $sandbox->commitAll('docs: list a page');

        self::assertStringContainsString('the page ceiling of docs/guide/big.md is new', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAPageCeilingAboveThePageWordsMustBeLowered(): void
    {
        $sandbox = $this->startedChange(['docs/guide/big.md' => "# Big\n\n".$this->chapters(2000), DocsRatchetList::PATH => $this->listOf([], ['docs/guide/big.md' => 2002])]);
        $sandbox->write('docs/guide/big.md', "# Big\n\n".$this->chapters(1800));
        $sandbox->commitAll('docs: shorten the page');

        self::assertStringContainsString('docs/guide/big.md has 1802 words now; lower its ceiling from 2002 to 1802', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAPageCeilingOfAPageWithinTheLimitMustBeRemoved(): void
    {
        $sandbox = $this->startedChange(['docs/guide/big.md' => "# Big\n\n".$this->chapters(2000), DocsRatchetList::PATH => $this->listOf([], ['docs/guide/big.md' => 2002])]);
        $sandbox->write('docs/guide/big.md', "# Big\n\nShort.\n");
        $sandbox->commitAll('docs: shorten the page');

        self::assertStringContainsString('docs/guide/big.md is within the page limit now; remove its ceiling', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAnExcusedSummaryAddedToTheListFails(): void
    {
        $sandbox = $this->startedChange([DocsRatchetList::PATH => $this->listOf([])]);
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([], [], ['docs/architecture/big.md']));
        $sandbox->commitAll('docs: excuse a page');

        self::assertStringContainsString('docs/architecture/big.md added to the pages excused', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testASummaryExcuseThatIsNoLongerNeededMustBeRemoved(): void
    {
        $sandbox = $this->startedChange(['docs/architecture/big.md' => "## Big\n\n".$this->chapters(1600), DocsRatchetList::PATH => $this->listOf([], [], ['docs/architecture/big.md'])]);
        $sandbox->write('docs/architecture/big.md', "## Big\n\n## In short\n\n".$this->words(50)."\n\n## Detail\n\n".$this->chapters(1600));
        $sandbox->commitAll('docs: add the summary');

        self::assertStringContainsString('docs/architecture/big.md no longer needs its "In short" excuse; remove it', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testASummaryExcuseForAPageWhoseSummaryIsTooLongIsStillNeeded(): void
    {
        $page = "## Big\n\n## In short\n\n".$this->words(70)."\n\n".$this->words(70)."\n\n## Detail\n\n".$this->chapters(1600);
        $sandbox = $this->startedChange(['docs/architecture/big.md' => $page, DocsRatchetList::PATH => $this->listOf([], [], ['docs/architecture/big.md'])]);
        $sandbox->write('docs/architecture/big.md', $page."\nOne more line.\n");
        $sandbox->commitAll('docs: add a line');

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'), $sandbox->runGate(new DocsGate())->output());
    }

    public function testRemovedAndLoweredEntriesPass(): void
    {
        $paragraph = $this->words(120);
        $sandbox = $this->startedChange([
            DocsRatchetList::PATH => $this->listOf(['docs/user/a.md' => [$paragraph, $this->words(90)]], ['docs/guide/big.md' => 2002], ['docs/architecture/big.md']),
            'docs/user/a.md' => "# A\n\n".$paragraph."\n\n".$this->words(90)."\n",
            'docs/guide/big.md' => "# Big\n\n".$this->chapters(2000),
        ]);
        $sandbox->write('docs/user/a.md', "# A\n\n".$paragraph."\n");
        $sandbox->write('docs/guide/big.md', "# Big\n\n".$this->chapters(1800));
        $sandbox->write(DocsRatchetList::PATH, $this->listOf(['docs/user/a.md' => [$paragraph]], ['docs/guide/big.md' => 1802]));
        $sandbox->commitAll('docs: shrink the list');

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'), $sandbox->runGate(new DocsGate())->output());
    }

    public function testDeletingTheListFails(): void
    {
        $sandbox = $this->startedChange([DocsRatchetList::PATH => $this->listOf([])]);
        $sandbox->git('rm', '--quiet', DocsRatchetList::PATH);
        $sandbox->commitAll('chore: remove the empty list');

        self::assertStringContainsString('ratchet/docs.json is missing; keep it in place', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testATargetBranchThatRunsTheGateWithoutTheListFails(): void
    {
        $sandbox = $this->startedChange([DocsGate::SOURCE => "<?php\n"]);
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([]));
        $sandbox->commitAll('docs: bring the list back');

        self::assertStringContainsString('ratchet/docs.json is missing on the target branch, which runs the docs gate', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testATargetBranchWithoutTheGateSkipsTheRatchetChecks(): void
    {
        $sandbox = $this->changeWith(['docs/user/start.md' => "# Start\n\nShort.\n"]);

        self::assertStringContainsString('ratchet checks skipped, the target branch has no docs gate yet', $sandbox->runGate(new DocsGate())->noteOf('docs'));
    }

    public function testAUserPageOverThePageLimitFails(): void
    {
        $sandbox = $this->changeWith(['docs/user/long.md' => "# Long\n\n".$this->chapters(1501)]);

        self::assertStringContainsString('docs/user/long.md: page of 1503 words, the limit is 1500', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAGuidePageOverTheLimitPassesUpToItsCeilingOnTheList(): void
    {
        $sandbox = $this->changeWith(['docs/guide/long.md' => "# Long\n\n".$this->chapters(2000), DocsRatchetList::PATH => $this->listOf([], ['docs/guide/long.md' => 2002])]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testAGuidePageOverItsCeilingFails(): void
    {
        $sandbox = $this->changeWith(['docs/guide/long.md' => "# Long\n\n".$this->chapters(2001), DocsRatchetList::PATH => $this->listOf([], ['docs/guide/long.md' => 2002])]);

        self::assertStringContainsString('the limit is 2002', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAnArchitecturePageIsNotHeldToThePageLimit(): void
    {
        $sandbox = $this->changeWith(['docs/architecture/big.md' => "## Big\n\n### In short\n\n".$this->words(50)."\n\n## Detail\n\n".$this->chapters(3000)]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testALongArchitecturePageWithoutInShortFails(): void
    {
        $sandbox = $this->changeWith(['docs/architecture/big.md' => "## Big\n\n## Detail\n\n".$this->chapters(1600)]);

        self::assertStringContainsString('docs/architecture/big.md: a page over 1500 words opens with an "In short" section of at most 120 words', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testProseBetweenTheTitleAndInShortFails(): void
    {
        $sandbox = $this->changeWith(['docs/architecture/big.md' => "# Big\n\n".$this->words(60)."\n\n## In short\n\n".$this->words(10)."\n\n## Detail\n\n".$this->chapters(1600)]);

        self::assertStringContainsString('opens with an "In short" section', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAnEmptyInShortFails(): void
    {
        $sandbox = $this->changeWith(['docs/architecture/big.md' => "# Big\n\n## In short\n\n## Detail\n\n".$this->chapters(1600)]);

        self::assertStringContainsString('opens with an "In short" section', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAnInShortSectionOverTheLimitFails(): void
    {
        $sandbox = $this->changeWith(['docs/architecture/big.md' => "## Big\n\n### In short\n\n".$this->words(60)."\n\n".$this->words(61)."\n\n## Detail\n\n".$this->chapters(1600)]);

        self::assertStringContainsString('the "In short" section has 121 words, the limit is 120', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testTheSubsectionsOfInShortCountTowardItsLimit(): void
    {
        $sandbox = $this->changeWith(['docs/architecture/big.md' => "# Big\n\n## In short\n\n".$this->words(70)."\n\n### Part\n\n".$this->words(70)."\n\n## Detail\n\n".$this->chapters(1600)]);

        self::assertStringContainsString('the "In short" section has 141 words', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testOnlyTheFirstInShortIsTheSummary(): void
    {
        $sandbox = $this->changeWith(['docs/architecture/big.md' => "# Big\n\n## In short\n\n".$this->words(70)."\n\n## Detail\n\n".$this->chapters(1600)."\n## In short\n\n".$this->words(70)."\n"]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'), $sandbox->runGate(new DocsGate())->output());
    }

    public function testAShortArchitecturePageNeedsNoSummary(): void
    {
        $sandbox = $this->changeWith(['docs/architecture/small.md' => "## Small\n\n".$this->chapters(1000)]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testAnArchitecturePageOnTheSummaryListPasses(): void
    {
        $sandbox = $this->changeWith(['docs/architecture/big.md' => "## Big\n\n".$this->chapters(1600), DocsRatchetList::PATH => $this->listOf([], [], ['docs/architecture/big.md'])]);

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'));
    }

    public function testWithoutGitHistoryTheLimitsStillApply(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([]));
        $sandbox->write('docs/user/start.md', "# Start\n\n".$this->words(81)."\n");

        self::assertTrue($sandbox->runGate(new DocsGate())->failed('docs'));
    }

    public function testWithoutGitHistoryTheRatchetChecksAreSkipped(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([]));
        $sandbox->write('docs/user/start.md', "# Start\n\n".$this->words(10)."\n");

        self::assertStringContainsString('ratchet checks skipped, no git history', $sandbox->runGate(new DocsGate())->noteOf('docs'));
    }

    public function testAnUnlistedOversizedParagraphAlreadyOnTheTargetBranchPasses(): void
    {
        $sandbox = $this->startedChange(['docs/user/start.md' => "# Start\n\n".$this->words(120)."\n", DocsRatchetList::PATH => $this->listOf([])]);
        $sandbox->write('docs/user/other.md', "# Other\n\nShort.\n");
        $sandbox->commitAll('docs: add a page');

        $run = $sandbox->runGate(new DocsGate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testAnOversizedParagraphOfATargetBranchWithoutTheGatePasses(): void
    {
        $sandbox = $this->startedChange(['docs/user/start.md' => "# Start\n\n".$this->words(120)."\n"]);
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([]));
        $sandbox->commitAll('docs: add the list');

        $run = $sandbox->runGate(new DocsGate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testEditingAnUnlistedOversizedParagraphOfTheTargetBranchFails(): void
    {
        $sandbox = $this->startedChange(['docs/user/start.md' => "# Start\n\n".$this->words(120)."\n", DocsRatchetList::PATH => $this->listOf([])]);
        $sandbox->write('docs/user/start.md', "# Start\n\n".$this->words(121)."\n");
        $sandbox->commitAll('docs: grow the paragraph');

        self::assertStringContainsString('docs/user/start.md:3: paragraph of 121 words', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testRemovingAListedParagraphThatIsStillThereFails(): void
    {
        $paragraph = $this->words(120);
        $sandbox = $this->startedChange(['docs/user/start.md' => "# Start\n\n".$paragraph."\n", DocsRatchetList::PATH => $this->listOf(['docs/user/start.md' => [$paragraph]])]);
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([]));
        $sandbox->commitAll('docs: empty the list');

        self::assertStringContainsString('docs/user/start.md:3: paragraph of 120 words', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAnUnlistedLongGuidePageOfTheTargetBranchPassesWhileItDoesNotGrow(): void
    {
        $sandbox = $this->startedChange(['docs/guide/long.md' => "# Long\n\n".$this->chapters(2000), DocsRatchetList::PATH => $this->listOf([])]);
        $sandbox->write('docs/guide/long.md', "# Long\n\n".$this->chapters(1900));
        $sandbox->commitAll('docs: shorten the page');

        $run = $sandbox->runGate(new DocsGate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testAnUnlistedLongGuidePageOfTheTargetBranchThatGrowsFails(): void
    {
        $sandbox = $this->startedChange(['docs/guide/long.md' => "# Long\n\n".$this->chapters(2000), DocsRatchetList::PATH => $this->listOf([])]);
        $sandbox->write('docs/guide/long.md', "# Long\n\n".$this->chapters(2100));
        $sandbox->commitAll('docs: grow the page');

        self::assertStringContainsString('docs/guide/long.md: page of 2102 words, the limit is 2002', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAnUnlistedArchitecturePageOfTheTargetBranchWithoutInShortPasses(): void
    {
        $sandbox = $this->startedChange(['docs/architecture/big.md' => "## Big\n\n".$this->chapters(1600), DocsRatchetList::PATH => $this->listOf([])]);
        $sandbox->write('docs/architecture/big.md', "## Big\n\n".$this->chapters(1700));
        $sandbox->commitAll('docs: grow the page');

        $run = $sandbox->runGate(new DocsGate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testRemovingTheSummaryExcuseOfAPageStillWithoutInShortFails(): void
    {
        $sandbox = $this->startedChange(['docs/architecture/big.md' => "## Big\n\n".$this->chapters(1600), DocsRatchetList::PATH => $this->listOf([], [], ['docs/architecture/big.md'])]);
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([]));
        $sandbox->commitAll('docs: drop the excuse');

        self::assertStringContainsString('docs/architecture/big.md: a page over 1500 words opens with an "In short" section', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testAListThatIsNotValidFailsTheGate(): void
    {
        $sandbox = $this->changeWith([DocsRatchetList::PATH => '{"paragraphs": []}']);

        self::assertStringContainsString('ratchet/docs.json is not valid', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    private function words(int $count): string
    {
        return trim(str_repeat('word ', $count));
    }

    /**
     * @param array<string, string> $files
     */
    private function changeWith(array $files): Sandbox
    {
        $sandbox = $this->startedChange([]);

        foreach ([DocsRatchetList::PATH => $this->listOf([]), ...$files] as $path => $contents) {
            $sandbox->write($path, $contents);
        }

        $sandbox->commitAll('docs: change the pages');

        return $sandbox;
    }

    /**
     * @param array<string, string> $baseFiles
     */
    private function startedChange(array $baseFiles): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('.gitkeep', '');

        foreach ($baseFiles as $path => $contents) {
            $sandbox->write($path, $contents);
        }

        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        return $sandbox;
    }

    /**
     * @param array<string, list<string>> $paragraphs
     * @param array<string, int>          $pages
     * @param list<string>                $summaries
     */
    private function listOf(array $paragraphs, array $pages = [], array $summaries = []): string
    {
        $keys = [];

        foreach ($paragraphs as $page => $texts) {
            $keys[$page] = array_map(static fn (string $text): string => new MarkdownParagraph(1, $text)->key(), $texts);
        }

        return json_encode(['paragraphs' => $keys, 'pages' => $pages, 'summaries' => $summaries], JSON_THROW_ON_ERROR);
    }

    // Because the page limit counts the title word too, a page of this many words plus "Long" stays under the paragraph limit in every chapter.
    private function chapters(int $words): string
    {
        return implode("\n\n", array_fill(0, intdiv($words, 50), $this->words(50)))."\n\n".$this->words($words % 50 + 1)."\n";
    }
}
