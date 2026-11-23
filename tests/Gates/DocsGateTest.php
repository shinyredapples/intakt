<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\DocsAllowances;
use Intakt\Gates\DocsGate;
use Intakt\Gates\DocsListChanges;
use Intakt\Gates\DocsListInvalid;
use Intakt\Gates\DocsPage;
use Intakt\Gates\DocsRatchetList;
use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\GateResult;
use Intakt\Gates\MarkdownCodeBlocks;
use Intakt\Gates\MarkdownDelimiterRow;
use Intakt\Gates\MarkdownLineKind;
use Intakt\Gates\MarkdownLineKinds;
use Intakt\Gates\MarkdownListIndents;
use Intakt\Gates\MarkdownPage;
use Intakt\Gates\MarkdownParagraph;
use Intakt\Gates\MarkdownReader;
use Intakt\Gates\MarkdownSummary;
use Intakt\Gates\UnneededDocsEntries;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(DocsGate::class)]
#[CoversClass(DocsAllowances::class)]
#[CoversClass(DocsListChanges::class)]
#[CoversClass(DocsListInvalid::class)]
#[CoversClass(DocsPage::class)]
#[CoversClass(DocsRatchetList::class)]
#[CoversClass(UnneededDocsEntries::class)]
#[UsesClass(FencedCodeBlocks::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(MarkdownCodeBlocks::class)]
#[UsesClass(MarkdownDelimiterRow::class)]
#[UsesClass(MarkdownLineKind::class)]
#[UsesClass(MarkdownLineKinds::class)]
#[UsesClass(MarkdownListIndents::class)]
#[UsesClass(MarkdownPage::class)]
#[UsesClass(MarkdownParagraph::class)]
#[UsesClass(MarkdownReader::class)]
#[UsesClass(MarkdownSummary::class)]
#[UsesClass(Workspace::class)]
final class DocsGateTest extends SandboxTestCase
{
    private const string GUIDE = 'docs/guide/start.md';
    private const string OTHER_GUIDE = 'docs/guide/other.md';
    private const string ARCHITECTURE = 'docs/architecture/topic.md';

    public function testAParagraphOverTheLimitFailsNamingTheFileTheLineAndTheLimit(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::GUIDE => "# Start\n\n".$this->wall('one'), DocsRatchetList::PATH => $this->list()]);

        self::assertSame(['docs/guide/start.md:3: paragraph of 81 words, over the limit of 80 words: split it or cut it, one idea per paragraph'], $result->findings);
    }

    public function testAParagraphOfExactlyTheLimitPasses(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::GUIDE => "# Start\n\n".$this->words(80), DocsRatchetList::PATH => $this->list()]);

        self::assertTrue($result->isPassing(), implode("\n", $result->findings));
    }

    public function testAListedParagraphPasses(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->wall('one'), DocsRatchetList::PATH => $this->list([self::GUIDE => [$this->keyOf('one') => 1]])]);

        self::assertTrue($result->isPassing(), implode("\n", $result->findings));
    }

    public function testAnUnchangedWallOfTheTargetBranchPasses(): void
    {
        $sandbox = $this->targetWith([self::GUIDE => $this->wall('one')]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->wall('one')."\n\nA short new paragraph.", DocsRatchetList::PATH => $this->list()]);

        self::assertTrue($result->isPassing(), implode("\n", $result->findings));
    }

    public function testASecondCopyOfAnUnlistedWallOfTheTargetBranchFails(): void
    {
        $sandbox = $this->targetWith([self::GUIDE => $this->wall('one')]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->wall('one')."\n\n".$this->wall('one'), DocsRatchetList::PATH => $this->list()]);

        self::assertSame(['docs/guide/start.md:3: paragraph of 81 words, over the limit of 80 words: split it or cut it, one idea per paragraph'], $result->findings);
    }

    public function testAThirdCopyFailsAloneWhereTheTargetHasTwoAndItsListCountsOne(): void
    {
        $list = $this->list([self::GUIDE => [$this->keyOf('one') => 1]]);
        $sandbox = $this->targetWith([self::GUIDE => $this->copies('one', 2), DocsRatchetList::PATH => $list]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->copies('one', 3)]);

        self::assertSame(['docs/guide/start.md:5: paragraph of 81 words, over the limit of 80 words: split it or cut it, one idea per paragraph'], $result->findings);
    }

    public function testAListedKeyDoesNotExcuseTheSameParagraphCopiedToAnotherPage(): void
    {
        $list = $this->list([self::GUIDE => [$this->keyOf('one') => 1]]);
        $sandbox = $this->targetWith([self::GUIDE => $this->wall('one'), DocsRatchetList::PATH => $list]);

        $result = $this->changeTo($sandbox, [self::OTHER_GUIDE => $this->wall('one')]);

        self::assertSame(['docs/guide/other.md:1: paragraph of 81 words, over the limit of 80 words: split it or cut it, one idea per paragraph'], $result->findings);
    }

    public function testAChangedWallFailsAndLeavesItsOldEntryStale(): void
    {
        $list = $this->list([self::GUIDE => [$this->keyOf('one') => 1]]);
        $sandbox = $this->targetWith([self::GUIDE => $this->wall('one'), DocsRatchetList::PATH => $list]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->wall('two')]);

        self::assertSame([
            'docs/guide/start.md:1: paragraph of 81 words, over the limit of 80 words: split it or cut it, one idea per paragraph',
            'ratchet/docs.json: paragraph '.$this->keyOf('one').' of docs/guide/start.md is gone or within 80 words: remove its entry',
        ], $result->findings);
    }

    public function testAGuidePageOverThePageLimitFailsNamingTheFileAndTheLimit(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->page(1600), DocsRatchetList::PATH => $this->list()]);

        self::assertSame(['docs/guide/start.md: page of 1601 words, over the limit of 1500 words for a page under docs/user/ and docs/guide/: split it into pages, or move detail only contributors need to docs/reference/ or docs/architecture/'], $result->findings);
    }

    public function testAnArchitecturePageOverThePageLimitHasNoPageLimit(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, ['docs/architecture/short.md' => "# Topic\n\n## In short\n\nShort.\n\n".$this->page(1600), DocsRatchetList::PATH => $this->list()]);

        self::assertTrue($result->isPassing(), implode("\n", $result->findings));
    }

    public function testAListedPagePassesWhileItDoesNotGrow(): void
    {
        $list = $this->list(pages: [self::GUIDE => 1601]);
        $sandbox = $this->targetWith([self::GUIDE => $this->page(1600), DocsRatchetList::PATH => $list]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->page(1590), DocsRatchetList::PATH => $this->list(pages: [self::GUIDE => 1591])]);

        self::assertTrue($result->isPassing(), implode("\n", $result->findings));
    }

    public function testARaisedPageCeilingFails(): void
    {
        $list = $this->list(pages: [self::GUIDE => 1601]);
        $sandbox = $this->targetWith([self::GUIDE => $this->page(1600), DocsRatchetList::PATH => $list]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->page(1601), DocsRatchetList::PATH => $this->list(pages: [self::GUIDE => 1602])]);

        self::assertSame(['ratchet/docs.json: the page ceiling of docs/guide/start.md rose from 1601 to 1602, but the list may only shrink: cut the page instead'], $result->findings);
    }

    public function testAListedPageThatGrowsFails(): void
    {
        $list = $this->list(pages: [self::GUIDE => 1601]);
        $sandbox = $this->targetWith([DocsRatchetList::PATH => $list]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->page(1700)]);

        self::assertSame(['docs/guide/start.md: page of 1701 words grew past its ceiling of 1601 (over the limit of 1500 words for a page under docs/user/ and docs/guide/): cut it back to at most 1601 words'], $result->findings);
    }

    public function testAnUnlistedPageOfTheTargetBranchFailsOnceItGrows(): void
    {
        $sandbox = $this->targetWith([self::GUIDE => $this->page(1600)]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->page(1610), DocsRatchetList::PATH => $this->list()]);

        self::assertSame(['docs/guide/start.md: page of 1611 words grew past its ceiling of 1601 (over the limit of 1500 words for a page under docs/user/ and docs/guide/): cut it back to at most 1601 words'], $result->findings);
    }

    public function testALargeArchitecturePageWithoutInShortFailsNamingTheFileAndTheLimit(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::ARCHITECTURE => $this->page(1600), DocsRatchetList::PATH => $this->list()]);

        self::assertSame(['docs/architecture/topic.md: page of 1601 words, over the limit of 1500 words for a page under docs/architecture/, has no "In short" section: open it with an "In short" section of at most 120 words'], $result->findings);
    }

    public function testAnInShortSectionOverItsLimitFails(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::ARCHITECTURE => "# Topic\n\n## In short\n\n".$this->words(60)."\n\n".$this->words(61)."\n\n## Detail\n\n".$this->page(1600), DocsRatchetList::PATH => $this->list()]);

        self::assertCount(1, $result->findings);
        self::assertStringContainsString('has an "In short" section of 121 words', $result->findings[0]);
    }

    public function testALargeArchitecturePageThatOpensWithAShortInShortPasses(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::ARCHITECTURE => "# Topic\n\n## In short\n\n".$this->words(60)."\n\n".$this->words(58)."\n\n## Detail\n\n".$this->page(1600), DocsRatchetList::PATH => $this->list()]);

        self::assertTrue($result->isPassing(), implode("\n", $result->findings));
    }

    public function testALargeArchitecturePageWithAnEmptyInShortFails(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::ARCHITECTURE => "# Topic\n\n## In short\n\n## Detail\n\n".$this->page(1600), DocsRatchetList::PATH => $this->list()]);

        self::assertSame(['docs/architecture/topic.md: page of 1605 words, over the limit of 1500 words for a page under docs/architecture/, has an empty "In short" section: open it with an "In short" section of at most 120 words'], $result->findings);
    }

    public function testAPageDeletedInTheWorkingTreeBeforeItIsStagedIsNotMeasured(): void
    {
        $sandbox = $this->targetWith([self::GUIDE => 'Short.', DocsRatchetList::PATH => $this->list()]);
        unlink($sandbox->root.'/'.self::GUIDE);

        $result = $sandbox->gateResult($this->gate());

        self::assertTrue($result->isPassing(), implode("\n", $result->findings));
    }

    public function testAPageThatExistsButCannotBeReadFails(): void
    {
        $sandbox = $this->targetWith([self::GUIDE => 'Short.', DocsRatchetList::PATH => $this->list()]);
        unlink($sandbox->root.'/'.self::GUIDE);
        $sandbox->write(self::GUIDE.'/inside.txt', 'Not a page.');

        $result = $sandbox->gateResult($this->gate());

        self::assertSame(['cannot read docs/guide/start.md'], $result->findings);
    }

    public function testAKeyOfDigitsAloneIsComparedAsAKey(): void
    {
        $list = $this->list([self::GUIDE => ['1234567890123456' => 1]]);
        $sandbox = $this->targetWith([self::GUIDE => 'Short.', DocsRatchetList::PATH => $list]);

        $result = $this->changeTo($sandbox, [DocsRatchetList::PATH => $list]);

        self::assertSame(['ratchet/docs.json: paragraph 1234567890123456 of docs/guide/start.md is gone or within 80 words: remove its entry'], $result->findings);
    }

    public function testATargetBranchThatGitCannotReadFailsInsteadOfCountingAsAFirstRun(): void
    {
        $sandbox = $this->targetWith([DocsRatchetList::PATH => $this->list(), 'config/gates/src/DocsGate.php' => "<?php\n"]);
        $this->changeTo($sandbox, [self::GUIDE => $this->wall('one'), DocsRatchetList::PATH => $this->list([self::GUIDE => [$this->keyOf('one') => 1]])]);

        foreach ([DocsRatchetList::PATH, 'config/gates/src/DocsGate.php'] as $path) {
            $blob = trim($sandbox->git('rev-parse', 'main:'.$path)->output);
            unlink($sandbox->root.'/.git/objects/'.substr($blob, 0, 2).'/'.substr($blob, 2));
        }

        $result = $sandbox->gateResult($this->gate());

        self::assertSame(['cannot read ratchet/docs.json on the target branch: git show failed'], $result->findings);
    }

    public function testAnUnlistedArchitecturePageOfTheTargetBranchWithoutInShortPasses(): void
    {
        $sandbox = $this->targetWith([self::ARCHITECTURE => $this->page(1600)]);

        $result = $this->changeTo($sandbox, [self::ARCHITECTURE => $this->page(1590), DocsRatchetList::PATH => $this->list()]);

        self::assertTrue($result->isPassing(), implode("\n", $result->findings));
    }

    public function testAnUnlistedArchitecturePageOfTheTargetBranchWithoutInShortFailsOnceItGrows(): void
    {
        $sandbox = $this->targetWith([self::ARCHITECTURE => $this->page(1600)]);

        $result = $this->changeTo($sandbox, [self::ARCHITECTURE => $this->page(1601), DocsRatchetList::PATH => $this->list()]);

        self::assertSame(['docs/architecture/topic.md: page of 1602 words, over the limit of 1500 words for a page under docs/architecture/, has no "In short" section: open it with an "In short" section of at most 120 words'], $result->findings);
    }

    public function testAListedArchitecturePageWithoutInShortPassesWhileItDoesNotGrow(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::ARCHITECTURE => $this->page(1600), DocsRatchetList::PATH => $this->list(summaries: [self::ARCHITECTURE => 1601])]);

        self::assertTrue($result->isPassing(), implode("\n", $result->findings));
    }

    public function testAStaleKeyFailsWhileTheTargetBranchHasNoGate(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::GUIDE => 'Short.', DocsRatchetList::PATH => $this->list([self::GUIDE => [$this->keyOf('one') => 1]])]);

        self::assertSame(['ratchet/docs.json: paragraph '.$this->keyOf('one').' of docs/guide/start.md is gone or within 80 words: remove its entry'], $result->findings);
    }

    public function testACountAboveTheCopiesOnThePageFailsWhileTheTargetBranchHasNoGate(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->wall('one'), DocsRatchetList::PATH => $this->list([self::GUIDE => [$this->keyOf('one') => 2]])]);

        self::assertSame(['ratchet/docs.json: paragraph '.$this->keyOf('one').' of docs/guide/start.md is counted 2 times but the page has it 1 times: lower the count to 1'], $result->findings);
    }

    public function testALooseCeilingFailsWhileTheTargetBranchHasNoGate(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->page(1600), DocsRatchetList::PATH => $this->list(pages: [self::GUIDE => 1700])]);

        self::assertSame(['ratchet/docs.json: the page ceiling of docs/guide/start.md is 1700 words but the page has 1601: lower it to 1601'], $result->findings);
    }

    public function testACeilingOfAPageWithinTheLimitFails(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [self::ARCHITECTURE => 'Short.', DocsRatchetList::PATH => $this->list(summaries: [self::ARCHITECTURE => 1700])]);

        self::assertSame(['ratchet/docs.json: the summary ceiling of docs/architecture/topic.md is no longer needed (the page is gone or meets the summary rule): remove its entry'], $result->findings);
    }

    public function testDeletingTheListFails(): void
    {
        $sandbox = $this->targetWith([DocsRatchetList::PATH => $this->list(), 'config/gates/src/DocsGate.php' => "<?php\n"]);
        unlink($sandbox->root.'/'.DocsRatchetList::PATH);

        $result = $this->changeTo($sandbox, []);

        self::assertSame(['ratchet/docs.json is missing or unreadable: the docs gate needs its list of the walls the documentation already has; restore it from the target branch'], $result->findings);
    }

    public function testEmptyingTheListFileFails(): void
    {
        $sandbox = $this->targetWith([DocsRatchetList::PATH => $this->list()]);

        $result = $this->changeTo($sandbox, [DocsRatchetList::PATH => '']);

        self::assertCount(1, $result->findings);
        self::assertStringStartsWith('ratchet/docs.json is not valid JSON: ', $result->findings[0]);
    }

    public function testDroppingTheEntriesOfWallsThatStayFails(): void
    {
        $sandbox = $this->targetWith([self::GUIDE => $this->wall('one'), DocsRatchetList::PATH => $this->list([self::GUIDE => [$this->keyOf('one') => 1]])]);

        $result = $this->changeTo($sandbox, [DocsRatchetList::PATH => $this->list()]);

        self::assertSame(['ratchet/docs.json: paragraph '.$this->keyOf('one').' of docs/guide/start.md was dropped from the list while the page still has it: restore its count of 1'], $result->findings);
    }

    public function testDroppingTheEntryOfAWallThatWentPasses(): void
    {
        $sandbox = $this->targetWith([self::GUIDE => $this->wall('one'), DocsRatchetList::PATH => $this->list([self::GUIDE => [$this->keyOf('one') => 1]])]);

        $result = $this->changeTo($sandbox, [self::GUIDE => 'Short.', DocsRatchetList::PATH => $this->list()]);

        self::assertTrue($result->isPassing(), implode("\n", $result->findings));
    }

    public function testDroppingTheCeilingOfAPageStillOverTheLimitFails(): void
    {
        $sandbox = $this->targetWith([self::GUIDE => $this->page(1600), DocsRatchetList::PATH => $this->list(pages: [self::GUIDE => 1601])]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->page(1590), DocsRatchetList::PATH => $this->list()]);

        self::assertSame(['ratchet/docs.json: the page ceiling of docs/guide/start.md was dropped or lowered below the page: restore it to 1591'], $result->findings);
    }

    public function testAListEntryThatRisesFails(): void
    {
        $sandbox = $this->targetWith([DocsRatchetList::PATH => $this->list()]);

        $result = $this->changeTo($sandbox, [self::GUIDE => $this->wall('one'), DocsRatchetList::PATH => $this->list([self::GUIDE => [$this->keyOf('one') => 1]])]);

        self::assertSame(['ratchet/docs.json: paragraph '.$this->keyOf('one').' of docs/guide/start.md rose from 0 to 1, but the list may only shrink: bring the paragraph within 80 words instead'], $result->findings);
    }

    public function testATargetBranchThatRunsTheGateWithoutTheListFails(): void
    {
        $sandbox = $this->targetWith(['config/gates/src/DocsGate.php' => "<?php\n"]);

        $result = $this->changeTo($sandbox, [DocsRatchetList::PATH => $this->list()]);

        self::assertSame(['ratchet/docs.json is missing on the target branch, which runs the docs gate: restore it there first'], $result->findings);
    }

    public function testAListWithAKeyThatIsNoHashFails(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [DocsRatchetList::PATH => $this->list([self::GUIDE => ['not-a-key' => 1]])]);

        self::assertSame(['ratchet/docs.json "paragraphs" of docs/guide/start.md holds not-a-key, which is no key of 16 lowercase hex characters; restore it from the target branch'], $result->findings);
    }

    public function testAListWithACountBelowOneFails(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [DocsRatchetList::PATH => $this->list(pages: [self::GUIDE => 0])]);

        self::assertSame(['ratchet/docs.json "pages" gives docs/guide/start.md no whole number of at least 1; restore it from the target branch'], $result->findings);
    }

    public function testAListWithoutItsSectionsFails(): void
    {
        $sandbox = $this->targetWith([]);

        $result = $this->changeTo($sandbox, [DocsRatchetList::PATH => '{"paragraphs":{}}']);

        self::assertSame(['ratchet/docs.json must be an object of exactly "paragraphs", "pages" and "summaries", in that order; restore it from the target branch'], $result->findings);
    }

    public function testATreeWithoutGitHistoryIsCheckedAgainstTheListAlone(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(self::GUIDE, $this->wall('one')."\n\n".$this->wall('two'));
        $sandbox->write(DocsRatchetList::PATH, $this->list([self::GUIDE => [$this->keyOf('one') => 1]]));

        $result = $sandbox->gateResult($this->gate());

        self::assertSame(['docs/guide/start.md:3: paragraph of 81 words, over the limit of 80 words: split it or cut it, one idea per paragraph'], $result->findings);
    }

    public function testATreeWithoutGitHistoryWhoseListCoversItPassesWithANote(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(self::GUIDE, $this->wall('one'));
        $sandbox->write(DocsRatchetList::PATH, $this->list([self::GUIDE => [$this->keyOf('one') => 1]]));

        $run = $sandbox->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
        self::assertSame('checked against the list alone, no git history in this tree', $run->noteOf('docs'));
    }

    public function testBinCheckRunsTheDocsGate(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(self::GUIDE, $this->wall('one'));
        $sandbox->write(DocsRatchetList::PATH, $this->list());

        $run = $sandbox->check('--only=docs');

        self::assertTrue($run->failed('docs'), $run->output());
        self::assertStringContainsString('docs/guide/start.md:1: paragraph of 81 words', $run->findingsOf('docs'));
    }

    /**
     * @param array<string, string> $files
     */
    private function targetWith(array $files): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();

        foreach ($files as $path => $contents) {
            $sandbox->write($path, $contents);
        }

        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        return $sandbox;
    }

    private function wall(string $name): string
    {
        return 'Wall '.$name.' '.$this->words(79);
    }

    /**
     * @param array<string, array<string, int>> $paragraphs
     * @param array<string, int>                $pages
     * @param array<string, int>                $summaries
     */
    private function list(array $paragraphs = [], array $pages = [], array $summaries = []): string
    {
        return json_encode([
            'paragraphs' => (object) array_map(static fn (array $keys): object => (object) $keys, $paragraphs),
            'pages' => (object) $pages,
            'summaries' => (object) $summaries,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, string> $files
     */
    private function changeTo(Sandbox $sandbox, array $files): GateResult
    {
        foreach ($files as $path => $contents) {
            $sandbox->write($path, $contents);
        }

        $sandbox->commitAll('docs: change the pages');

        return $sandbox->gateResult($this->gate());
    }

    private function words(int $count): string
    {
        return trim(str_repeat('word ', $count));
    }

    private function keyOf(string $name): string
    {
        return new MarkdownParagraph($this->wall($name), 0, 1)->key();
    }

    private function copies(string $name, int $count): string
    {
        return implode("\n\n", array_fill(0, $count, $this->wall($name)));
    }

    // One title word, then paragraphs of 50 words, so the page has one word more than asked.
    private function page(int $words): string
    {
        $paragraphs = array_fill(0, intdiv($words, 50), $this->words(50));

        if (0 !== $words % 50) {
            $paragraphs[] = $this->words($words % 50);
        }

        return "# Title\n\n".implode("\n\n", $paragraphs);
    }

    private function gate(): DocsGate
    {
        return new DocsGate(new MarkdownReader(new MarkdownCodeBlocks(new FencedCodeBlocks(), new MarkdownListIndents())));
    }
}
