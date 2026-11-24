<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\DocsGate;
use Intakt\Gates\DocsLimits;
use Intakt\Gates\DocsRatchetList;
use Intakt\Gates\DocsRatchetListUnusable;
use Intakt\Gates\FencedCodeBlocks;
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
#[CoversClass(DocsLimits::class)]
#[CoversClass(DocsRatchetList::class)]
#[CoversClass(DocsRatchetListUnusable::class)]
#[CoversClass(UnneededDocsEntries::class)]
#[UsesClass(FencedCodeBlocks::class)]
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
    private const string USER_PAGE = 'docs/user/start.md';
    private const string GUIDE_PAGE = 'docs/guide/long.md';
    private const string ARCHITECTURE_PAGE = 'docs/architecture/big.md';

    public function testBinCheckRunsTheDocsGate(): void
    {
        $run = $this->changeWith([self::USER_PAGE => $this->words(81)."\n"])->check('--only=docs', '--base=main');

        self::assertTrue($run->failed('docs'), $run->output());
        self::assertStringContainsString('docs/user/start.md:1: paragraph of 81 words', $run->findingsOf('docs'));
    }

    public function testShortPagesWithShortParagraphsPass(): void
    {
        $run = $this->changeWith([self::USER_PAGE => "# Start\n\n".$this->words(30)."\n\n1. ".$this->words(20)."\n"])->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testAParagraphOverTheLimitFailsNamingTheFileTheLineAndTheLimit(): void
    {
        $run = $this->changeWith([self::USER_PAGE => "# Start\n\nShort.\n\n".$this->words(81)."\n"])->runGate($this->gate());

        self::assertTrue($run->failed('docs'), $run->output());
        self::assertStringContainsString('docs/user/start.md:5: paragraph of 81 words, the limit is 80 words; split it into shorter paragraphs', $run->findingsOf('docs'));
    }

    public function testAParagraphAtTheLimitPasses(): void
    {
        $run = $this->changeWith([self::USER_PAGE => "# Start\n\n".$this->words(80)."\n"])->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testTheReadmeIsJudgedLikeAPageUnderDocs(): void
    {
        $run = $this->changeWith(['README.md' => "# Intakt\n\n".$this->words(90)."\n"])->runGate($this->gate());

        self::assertStringContainsString('README.md:3: paragraph of 90 words', $run->findingsOf('docs'));
    }

    public function testMarkdownOutsideTheDocumentationIsNotJudged(): void
    {
        $run = $this->changeWith(['notes/long.md' => $this->words(200)."\n"])->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testAListedOversizedParagraphPasses(): void
    {
        $paragraph = $this->words(120);
        $run = $this->changeWith([self::USER_PAGE => "# Start\n\n".$paragraph."\n", DocsRatchetList::PATH => $this->listOf([self::USER_PAGE => [$paragraph]])])->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testAChangedListedParagraphFailsBecauseItsKeyNoLongerMatches(): void
    {
        $paragraph = $this->words(120);
        $run = $this->changeWith([self::USER_PAGE => "# Start\n\n".$paragraph." more\n", DocsRatchetList::PATH => $this->listOf([self::USER_PAGE => [$paragraph]])])->runGate($this->gate());

        self::assertStringContainsString('docs/user/start.md:3: paragraph of 121 words', $run->findingsOf('docs'));
    }

    public function testACopyOfAListedParagraphOnTheSamePageFails(): void
    {
        $paragraph = $this->words(81);
        $run = $this->changeWith([self::USER_PAGE => $paragraph."\n\n".$paragraph."\n", DocsRatchetList::PATH => $this->listOf([self::USER_PAGE => [$paragraph]])])->runGate($this->gate());

        self::assertStringContainsString('docs/user/start.md:3: paragraph of 81 words', $run->findingsOf('docs'));
        self::assertStringNotContainsString('docs/user/start.md:1:', $run->findingsOf('docs'));
    }

    public function testAKeyListedForAnotherPageDoesNotExcuseTheParagraph(): void
    {
        $paragraph = $this->words(81);
        $files = [self::USER_PAGE => $paragraph."\n", 'docs/user/other.md' => $paragraph."\n", DocsRatchetList::PATH => $this->listOf(['docs/user/other.md' => [$paragraph]])];

        self::assertStringContainsString('docs/user/start.md:1: paragraph of 81 words', $this->changeWith($files)->runGate($this->gate())->findingsOf('docs'));
    }

    public function testAUserPageOverThePageLimitFailsNamingTheFileAndTheLimit(): void
    {
        $run = $this->changeWith([self::USER_PAGE => $this->pageOf(1501)])->runGate($this->gate());

        self::assertStringContainsString('docs/user/start.md: page of 1501 words, the limit is 1500 words for pages under docs/user/ and docs/guide/; split it', $run->findingsOf('docs'));
    }

    public function testAGuidePageAtThePageLimitPasses(): void
    {
        $run = $this->changeWith([self::GUIDE_PAGE => $this->pageOf(1500)])->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testAGuidePageAtItsCeilingPasses(): void
    {
        $run = $this->changeWith([self::GUIDE_PAGE => $this->pageOf(2000), DocsRatchetList::PATH => $this->listOf([], [self::GUIDE_PAGE => 2000])])->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testAGuidePageOverItsCeilingFails(): void
    {
        $run = $this->changeWith([self::GUIDE_PAGE => $this->pageOf(2001), DocsRatchetList::PATH => $this->listOf([], [self::GUIDE_PAGE => 2000])])->runGate($this->gate());

        self::assertStringContainsString('docs/guide/long.md: page of 2001 words, above its ceiling of 2000 in ratchet/docs.json (the limit is 1500 words)', $run->findingsOf('docs'));
    }

    public function testAnArchitecturePageIsNotHeldToThePageLimit(): void
    {
        $run = $this->changeWith([self::ARCHITECTURE_PAGE => $this->summarizedPage($this->words(20), 3000)])->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testALargeArchitecturePageWithoutAnOpeningInShortFails(): void
    {
        $run = $this->changeWith([self::ARCHITECTURE_PAGE => $this->pageOf(1501)])->runGate($this->gate());

        self::assertStringContainsString('docs/architecture/big.md: a page under docs/architecture/ over 1500 words must open with an "In short" section of at most 120 words', $run->findingsOf('docs'));
    }

    public function testAnArchitecturePageAtTheSummaryThresholdNeedsNoSummary(): void
    {
        $run = $this->changeWith([self::ARCHITECTURE_PAGE => $this->pageOf(1500)])->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testAnInShortHoldingOnlyAnHtmlCommentFails(): void
    {
        $run = $this->changeWith([self::ARCHITECTURE_PAGE => $this->summarizedPage("<!--\nto be written\n-->", 1600)])->runGate($this->gate());

        self::assertStringContainsString('docs/architecture/big.md: the "In short" section holds no text a reader sees (headings and HTML comments do not count)', $run->findingsOf('docs'));
    }

    public function testAnInShortWithACommentNextToRealTextPasses(): void
    {
        $run = $this->changeWith([self::ARCHITECTURE_PAGE => $this->summarizedPage("<!-- keep this short -->\nThe page in one line.", 1600)])->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testAQuotedInShortDoesNotSatisfyTheSummaryRule(): void
    {
        $page = "# Big\n\n> ## In short\n>\n> ".$this->words(20)."\n\n## Detail\n\n".$this->chapters(1600);
        $run = $this->changeWith([self::ARCHITECTURE_PAGE => $page])->runGate($this->gate());

        self::assertStringContainsString('docs/architecture/big.md: a page under docs/architecture/ over 1500 words must open with an "In short" section', $run->findingsOf('docs'));
    }

    public function testAnInShortAtTheLimitPasses(): void
    {
        $run = $this->changeWith([self::ARCHITECTURE_PAGE => $this->summarizedPage($this->words(60)."\n\n".$this->words(60), 1600)])->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testAnInShortOverTheLimitFails(): void
    {
        $run = $this->changeWith([self::ARCHITECTURE_PAGE => $this->summarizedPage($this->words(60)."\n\n".$this->words(61), 1600)])->runGate($this->gate());

        self::assertStringContainsString('docs/architecture/big.md: the "In short" section has 121 words, the limit is 120', $run->findingsOf('docs'));
    }

    public function testAnArchitecturePageExcusedOnTheListPasses(): void
    {
        $run = $this->changeWith([self::ARCHITECTURE_PAGE => $this->pageOf(1600), DocsRatchetList::PATH => $this->listOf([], [], [self::ARCHITECTURE_PAGE])])->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
    }

    public function testDeletingTheListFails(): void
    {
        $sandbox = $this->startedChange([DocsRatchetList::PATH => $this->listOf([])]);
        $sandbox->git('rm', '--quiet', DocsRatchetList::PATH);
        $sandbox->commitAll('chore: remove the list');

        self::assertStringContainsString('ratchet/docs.json is missing; keep it in place', $sandbox->runGate($this->gate())->findingsOf('docs'));
    }

    public function testAnEmptiedListFails(): void
    {
        $run = $this->changeWith([DocsRatchetList::PATH => ''])->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json is not valid', $run->findingsOf('docs'));
    }

    public function testAListWithoutOneOfItsSectionsFails(): void
    {
        $run = $this->changeWith([DocsRatchetList::PATH => '{"paragraphs": {}, "pages": {}}'])->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json is not valid', $run->findingsOf('docs'));
    }

    public function testAListedKeyThatIsNoSixteenCharacterHashFails(): void
    {
        $run = $this->changeWith([DocsRatchetList::PATH => '{"paragraphs": {"docs/user/start.md": ["the paragraph"]}, "pages": {}, "summaries": []}'])->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json is not valid', $run->findingsOf('docs'));
    }

    public function testAnUnreadableListFails(): void
    {
        $this->skipWhenPermissionsAreIgnored();
        $sandbox = $this->changeWith([]);
        chmod($sandbox->root.'/'.DocsRatchetList::PATH, 0o000);

        self::assertStringContainsString('cannot read ratchet/docs.json', $sandbox->runGate($this->gate())->findingsOf('docs'));
    }

    public function testAnUnreadablePageFails(): void
    {
        $this->skipWhenPermissionsAreIgnored();
        $sandbox = $this->changeWith([self::USER_PAGE => "# Start\n"]);
        chmod($sandbox->root.'/'.self::USER_PAGE, 0o000);

        self::assertStringContainsString('cannot read docs/user/start.md', $sandbox->runGate($this->gate())->findingsOf('docs'));
    }

    public function testAStaleKeyFailsWhileTheTargetBranchHasNoGate(): void
    {
        $listed = $this->words(81);
        $run = $this->changeWith([self::USER_PAGE => "Short.\n", DocsRatchetList::PATH => $this->listOf([self::USER_PAGE => [$listed]])])->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json: docs/user/start.md lists paragraph keys that match no paragraph over 80 words any more ('.$this->keyOf($listed).'); remove them', $run->findingsOf('docs'));
    }

    public function testALooseCeilingFailsWhileTheTargetBranchHasNoGate(): void
    {
        $run = $this->changeWith([self::GUIDE_PAGE => $this->pageOf(1800), DocsRatchetList::PATH => $this->listOf([], [self::GUIDE_PAGE => 2000])])->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json: docs/guide/long.md has 1800 words now; lower its ceiling from 2000 to 1800', $run->findingsOf('docs'));
    }

    public function testACeilingOfAPageWithinTheLimitFails(): void
    {
        $run = $this->changeWith([self::GUIDE_PAGE => $this->pageOf(1500), DocsRatchetList::PATH => $this->listOf([], [self::GUIDE_PAGE => 2000])])->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json: docs/guide/long.md needs no page ceiling', $run->findingsOf('docs'));
    }

    public function testACeilingOfAPageOutsideTheLimitedFoldersFails(): void
    {
        $run = $this->changeWith([self::ARCHITECTURE_PAGE => $this->summarizedPage('Short.', 2000), DocsRatchetList::PATH => $this->listOf([], [self::ARCHITECTURE_PAGE => 2100])])->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json: docs/architecture/big.md needs no page ceiling', $run->findingsOf('docs'));
    }

    public function testAnUnneededSummaryExcuseFails(): void
    {
        $run = $this->changeWith([self::ARCHITECTURE_PAGE => $this->summarizedPage('Short.', 1600), DocsRatchetList::PATH => $this->listOf([], [], [self::ARCHITECTURE_PAGE])])->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json: docs/architecture/big.md meets the "In short" rule now; remove it from "summaries"', $run->findingsOf('docs'));
    }

    public function testAStaleKeyFailsWithoutGitHistory(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([self::USER_PAGE => [$this->words(81)]]));
        $sandbox->write(self::USER_PAGE, "Short.\n");

        self::assertStringContainsString('ratchet/docs.json: docs/user/start.md lists paragraph keys', $sandbox->runGate($this->gate())->findingsOf('docs'));
    }

    public function testWithoutGitHistoryTheLimitsStillApply(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([]));
        $sandbox->write(self::USER_PAGE, $this->words(81)."\n");

        self::assertStringContainsString('docs/user/start.md:1: paragraph of 81 words', $sandbox->runGate($this->gate())->findingsOf('docs'));
    }

    public function testWithoutGitHistoryTheListIsNotComparedWithTheTargetBranch(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([]));

        self::assertStringContainsString('list not compared with the target branch, no git history in this tree', $sandbox->runGate($this->gate())->noteOf('docs'));
    }

    public function testATargetBranchWithoutTheListLeavesTheListUncompared(): void
    {
        $run = $this->changeWith([])->runGate($this->gate());

        self::assertStringContainsString('list not compared with the target branch, which has no ratchet/docs.json yet', $run->noteOf('docs'));
    }

    public function testATargetBranchThatRunsTheGateWithoutTheListFails(): void
    {
        $run = $this->changeOver([DocsGate::SOURCE => "<?php\n"], [DocsRatchetList::PATH => $this->listOf([])])->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json is missing on the target branch, which runs the docs gate', $run->findingsOf('docs'));
    }

    public function testAnInvalidListOnTheTargetBranchFails(): void
    {
        $run = $this->changeOver([DocsRatchetList::PATH => '{}'], [DocsRatchetList::PATH => $this->listOf([])])->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json on the target branch is not valid', $run->findingsOf('docs'));
    }

    public function testAKeyAddedToTheListFails(): void
    {
        $paragraph = $this->words(120);
        $change = [self::USER_PAGE => $paragraph."\n", DocsRatchetList::PATH => $this->listOf([self::USER_PAGE => [$paragraph]])];
        $run = $this->changeOver([DocsRatchetList::PATH => $this->listOf([])], $change)->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json: 1 paragraph key(s) of docs/user/start.md added; the list only shrinks', $run->findingsOf('docs'));
    }

    public function testANewCeilingFails(): void
    {
        $change = [self::GUIDE_PAGE => $this->pageOf(2000), DocsRatchetList::PATH => $this->listOf([], [self::GUIDE_PAGE => 2000])];
        $run = $this->changeOver([DocsRatchetList::PATH => $this->listOf([])], $change)->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json: the page ceiling of docs/guide/long.md is new; the list only shrinks', $run->findingsOf('docs'));
    }

    public function testARaisedCeilingFails(): void
    {
        $base = [self::GUIDE_PAGE => $this->pageOf(2000), DocsRatchetList::PATH => $this->listOf([], [self::GUIDE_PAGE => 2000])];
        $change = [self::GUIDE_PAGE => $this->pageOf(2100), DocsRatchetList::PATH => $this->listOf([], [self::GUIDE_PAGE => 2100])];

        self::assertStringContainsString('the page ceiling of docs/guide/long.md is raised from 2000 to 2100', $this->changeOver($base, $change)->runGate($this->gate())->findingsOf('docs'));
    }

    public function testAnAddedSummaryExcuseFails(): void
    {
        $change = [self::ARCHITECTURE_PAGE => $this->pageOf(1600), DocsRatchetList::PATH => $this->listOf([], [], [self::ARCHITECTURE_PAGE])];
        $run = $this->changeOver([DocsRatchetList::PATH => $this->listOf([])], $change)->runGate($this->gate());

        self::assertStringContainsString('ratchet/docs.json: docs/architecture/big.md added to the pages excused from the "In short" rule', $run->findingsOf('docs'));
    }

    public function testAListThatShrankWithItsPagesPasses(): void
    {
        $paragraph = $this->words(120);
        $base = [
            self::USER_PAGE => $paragraph."\n\n".$this->words(90)."\n",
            self::GUIDE_PAGE => $this->pageOf(2000),
            DocsRatchetList::PATH => $this->listOf([self::USER_PAGE => [$paragraph, $this->words(90)]], [self::GUIDE_PAGE => 2000]),
        ];
        $change = [self::USER_PAGE => $paragraph."\n", self::GUIDE_PAGE => $this->pageOf(1800), DocsRatchetList::PATH => $this->listOf([self::USER_PAGE => [$paragraph]], [self::GUIDE_PAGE => 1800])];
        $run = $this->changeOver($base, $change)->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
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
        return $this->changeOver([], [DocsRatchetList::PATH => $this->listOf([]), ...$files]);
    }

    /**
     * @param array<string, list<string>> $paragraphs
     * @param array<string, int>          $pages
     * @param list<string>                $summaries
     */
    private function listOf(array $paragraphs, array $pages = [], array $summaries = []): string
    {
        $keys = array_map(fn (array $texts): array => array_map($this->keyOf(...), $texts), $paragraphs);

        return json_encode(['paragraphs' => (object) $keys, 'pages' => (object) $pages, 'summaries' => $summaries], JSON_THROW_ON_ERROR);
    }

    private function keyOf(string $paragraph): string
    {
        return new MarkdownParagraph($paragraph, 0, 1)->key();
    }

    /**
     * @param array<string, string> $baseFiles
     * @param array<string, string> $changedFiles
     */
    private function changeOver(array $baseFiles, array $changedFiles): Sandbox
    {
        $sandbox = $this->startedChange($baseFiles);

        foreach ($changedFiles as $path => $contents) {
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

    private function gate(): DocsGate
    {
        return new DocsGate(new MarkdownReader(new MarkdownCodeBlocks(new FencedCodeBlocks(), new MarkdownListIndents())));
    }

    private function pageOf(int $words): string
    {
        return "# T\n\n".$this->chapters($words - 1);
    }

    // Because the paragraph limit is 80 words, a long page is built from paragraphs of at most 50.
    private function chapters(int $words): string
    {
        $chapters = array_fill(0, intdiv($words, 50), $this->words(50));

        if (0 !== $words % 50) {
            $chapters[] = $this->words($words % 50);
        }

        return implode("\n\n", $chapters)."\n";
    }

    private function summarizedPage(string $summary, int $detailWords): string
    {
        return "# Big\n\n## In short\n\n".$summary."\n\n## Detail\n\n".$this->chapters($detailWords);
    }
}
