<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\DocsGate;
use Intakt\Gates\DocsLimits;
use Intakt\Gates\DocsListGrowth;
use Intakt\Gates\DocsRatchetList;
use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\MarkdownCodeBlocks;
use Intakt\Gates\MarkdownDelimiterRow;
use Intakt\Gates\MarkdownLineKind;
use Intakt\Gates\MarkdownLineKinds;
use Intakt\Gates\MarkdownListIndents;
use Intakt\Gates\MarkdownPage;
use Intakt\Gates\MarkdownParagraph;
use Intakt\Gates\MarkdownReader;
use Intakt\Gates\UnneededDocsEntries;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(DocsGate::class)]
#[CoversClass(DocsRatchetList::class)]
#[CoversClass(UnneededDocsEntries::class)]
#[CoversClass(DocsLimits::class)]
#[CoversClass(DocsListGrowth::class)]
#[UsesClass(MarkdownReader::class)]
#[UsesClass(MarkdownCodeBlocks::class)]
#[UsesClass(FencedCodeBlocks::class)]
#[UsesClass(MarkdownPage::class)]
#[UsesClass(MarkdownParagraph::class)]
#[UsesClass(MarkdownLineKind::class)]
#[UsesClass(MarkdownLineKinds::class)]
#[UsesClass(MarkdownListIndents::class)]
#[UsesClass(MarkdownDelimiterRow::class)]
#[UsesClass(Workspace::class)]
final class DocsGateTest extends SandboxTestCase
{
    private const string LISTED_PAGE = 'docs/architecture/long.md';

    public function testATreeWhoseOversizedParagraphsAreListedPasses(): void
    {
        $result = $this->listedTree()->gateResult($this->gate());

        self::assertSame([], $result->findings);
    }

    public function testBinCheckRunsTheDocsGate(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('docs/architecture/new.md', $this->words(81, 'fresh')."\n");

        $run = $sandbox->check('--only=docs');

        self::assertTrue($run->failed('docs'), $run->output());
        self::assertStringContainsString('docs/architecture/new.md:1: paragraph of 81 words', $run->findingsOf('docs'));
    }

    public function testWithoutGitHistoryTheTargetBranchComparisonIsSkippedWithANote(): void
    {
        $run = $this->listedTree()->runGate($this->gate());

        self::assertTrue($run->passed('docs'), $run->output());
        self::assertStringContainsString('no git history', $run->noteOf('docs'));
    }

    public function testAParagraphOverTheLimitFailsNamingFileLineAndLimit(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('docs/architecture/new.md', "# New\n\n".$this->words(81, 'fresh')."\n");

        $findings = $sandbox->gateResult($this->gate())->findings;

        self::assertCount(1, $findings);
        self::assertStringStartsWith('docs/architecture/new.md:3: paragraph of 81 words, over the limit of 80 words per paragraph', $findings[0]);
        self::assertStringContainsString('split it or cut words', $findings[0]);
    }

    public function testAParagraphOfExactlyTheLimitPasses(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('docs/architecture/new.md', $this->words(80, 'fresh')."\n");

        self::assertSame([], $sandbox->gateResult($this->gate())->findings);
    }

    public function testAnOversizedParagraphInTheReadmeFails(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('README.md', $this->words(81, 'fresh')."\n");

        self::assertStringStartsWith('README.md:1: paragraph of 81 words', $sandbox->gateResult($this->gate())->findings[0] ?? '');
    }

    public function testMarkdownOutsideDocsAndTheReadmeIsNotMeasured(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('upstream/a/README.md', $this->words(81, 'fresh')."\n");

        self::assertSame([], $sandbox->gateResult($this->gate())->findings);
    }

    public function testAPageOverTheLimitInUserDocsFailsNamingFileAndLimit(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('docs/user/10-long.md', $this->page(1501));

        $findings = $sandbox->gateResult($this->gate())->findings;

        self::assertCount(1, $findings);
        self::assertStringStartsWith('docs/user/10-long.md: page of 1501 words, over the limit of 1500 words per page in docs/user/ and docs/guide/', $findings[0]);
        self::assertStringContainsString('split it into pages or cut words', $findings[0]);
    }

    public function testAPageOverTheLimitInTheGuideFails(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('docs/guide/long.md', $this->page(1501));

        self::assertStringStartsWith('docs/guide/long.md: page of 1501 words', $sandbox->gateResult($this->gate())->findings[0] ?? '');
    }

    public function testAPageOfExactlyTheLimitPasses(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('docs/guide/long.md', $this->page(1500));

        self::assertSame([], $sandbox->gateResult($this->gate())->findings);
    }

    public function testAPageOutsideUserAndGuideHasNoPageLimit(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('docs/architecture/big.md', $this->page(2251));

        self::assertSame([], $sandbox->gateResult($this->gate())->findings);
    }

    public function testAListedPageWithinItsCeilingPasses(): void
    {
        $sandbox = $this->listedTree(['docs/guide/long.md' => 1501]);
        $sandbox->write('docs/guide/long.md', $this->page(1501));

        self::assertSame([], $sandbox->gateResult($this->gate())->findings);
    }

    public function testAListedPageThatGrowsPastItsCeilingFails(): void
    {
        $sandbox = $this->listedTree(['docs/guide/long.md' => 1501]);
        $sandbox->write('docs/guide/long.md', $this->page(1501)."\nMore.\n");

        $findings = $sandbox->gateResult($this->gate())->findings;

        self::assertCount(1, $findings);
        self::assertStringStartsWith('docs/guide/long.md: page of 1502 words, over its ceiling of 1501 words in ratchet/docs.json and the limit of 1500 words per page', $findings[0]);
    }

    public function testAChangedListedParagraphFailsBecauseItsKeyNoLongerMatches(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write(self::LISTED_PAGE, "# Long\n\n".$this->words(90, 'changed')."\n");

        $findings = $sandbox->gateResult($this->gate())->findings;

        self::assertStringStartsWith(self::LISTED_PAGE.':3: paragraph of 90 words, over the limit of 80', $findings[0] ?? '');
        self::assertStringContainsString('paragraph '.$this->key($this->words(90, 'listed')).' of '.self::LISTED_PAGE.' is no longer on the page', implode("\n", $findings));
    }

    public function testDeletingTheListFails(): void
    {
        $sandbox = $this->listedTree();
        unlink($sandbox->root.'/ratchet/docs.json');

        self::assertSame(['ratchet/docs.json: missing; this list of oversized paragraphs and pages may only shrink, restore it from the target branch'], $sandbox->gateResult($this->gate())->findings);
    }

    public function testEmptyingTheListFails(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('ratchet/docs.json', '{"paragraphs": {}, "pages": {}}');

        self::assertSame(['ratchet/docs.json: empty; this list of oversized paragraphs and pages may only shrink, restore it from the target branch'], $sandbox->gateResult($this->gate())->findings);
    }

    public function testANumericPageKeyMakesTheListMalformed(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('ratchet/docs.json', '{"paragraphs": {}, "pages": {"1": 100}}');

        $findings = $sandbox->gateResult($this->gate())->findings;

        self::assertCount(1, $findings);
        self::assertStringStartsWith('ratchet/docs.json: malformed', $findings[0]);
    }

    public function testAParagraphKeyWithATrailingNewlineMakesTheListMalformed(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('ratchet/docs.json', '{"paragraphs": {"README.md": {"0123456789abcdef\n": 81}}, "pages": {}}');

        $findings = $sandbox->gateResult($this->gate())->findings;

        self::assertCount(1, $findings);
        self::assertStringStartsWith('ratchet/docs.json: malformed', $findings[0]);
    }

    #[DataProvider('malformedLists')]
    public function testAMalformedListFailsWithAFindingNotAnError(string $list): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('ratchet/docs.json', $list);

        $findings = $sandbox->gateResult($this->gate())->findings;

        self::assertCount(1, $findings);
        self::assertStringStartsWith('ratchet/docs.json: malformed, expected {"paragraphs": {"<page>.md": {"<16 hex key>": <words>}}, "pages": {"<page>.md": <words>}}', $findings[0]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedLists(): array
    {
        $paragraphs = '"paragraphs": {"docs/a.md": {"0123456789abcdef": 90}}';

        return [
            'not JSON' => ['{"paragraphs":'],
            'not an object' => ['[1, 2]'],
            'no pages' => ['{'.$paragraphs.'}'],
            'an unknown key' => ['{'.$paragraphs.', "pages": {}, "summaries": {}}'],
            'a numeric page key in paragraphs' => ['{"paragraphs": {"7": {"0123456789abcdef": 90}}, "pages": {}}'],
            'a page key that is not Markdown' => ['{"paragraphs": {}, "pages": {"docs/a.txt": 1600}}'],
            'a key that is not 16 hex characters' => ['{"paragraphs": {"docs/a.md": {"0123456789ABCDEF": 90}}, "pages": {}}'],
            'a count that is not a number' => ['{"paragraphs": {}, "pages": {"docs/user/a.md": "1600"}}'],
            'a count of zero' => ['{"paragraphs": {}, "pages": {"docs/user/a.md": 0}}'],
            'a page without keys' => ['{"paragraphs": {"docs/a.md": {}}, "pages": {}}'],
            'keys that are a list' => ['{"paragraphs": {"docs/a.md": [90]}, "pages": {}}'],
        ];
    }

    public function testAJsonListInPlaceOfAnObjectMakesTheListMalformed(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('ratchet/docs.json', '{"paragraphs": {"'.self::LISTED_PAGE.'": {"'.$this->key($this->words(90, 'listed')).'": 90}}, "pages": []}');

        $findings = $sandbox->gateResult($this->gate())->findings;

        self::assertCount(1, $findings);
        self::assertStringStartsWith('ratchet/docs.json: malformed', $findings[0]);
    }

    public function testACopyOfAListedParagraphOnTheSamePageFails(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write(self::LISTED_PAGE, "# Long\n\n".$this->words(90, 'listed')."\n\n".$this->words(90, 'listed')."\n");

        self::assertSame([self::LISTED_PAGE.':5: paragraph of 90 words, over the limit of 80 words per paragraph; split it or cut words'], $sandbox->gateResult($this->gate())->findings);
    }

    public function testAnUnreadableListOnTheTargetBranchFails(): void
    {
        $sandbox = $this->changeOnAMainWithTheGate();
        $blob = trim($sandbox->git('rev-parse', 'main:ratchet/docs.json')->output);
        unlink($sandbox->root.'/.git/objects/'.substr($blob, 0, 2).'/'.substr($blob, 2));
        $sandbox->write('docs/architecture/new.md', $this->words(81, 'fresh')."\n");
        $sandbox->write('ratchet/docs.json', $this->listJson([...$this->listedParagraph(), 'docs/architecture/new.md' => [$this->key($this->words(81, 'fresh')) => 81]]));

        $findings = $sandbox->gateResult($this->gate(), 'main')->findings;

        self::assertCount(1, $findings);
        self::assertStringStartsWith('ratchet/docs.json on the target branch: unreadable', $findings[0]);
    }

    public function testAListedKeyOfOnlyDigitsIsRead(): void
    {
        $list = DocsRatchetList::fromJson('{"paragraphs": {"docs/a.md": {"1234567890123456": 90}}, "pages": {}}');

        self::assertInstanceOf(DocsRatchetList::class, $list);
        self::assertSame(90, $list->paragraphCeiling('docs/a.md', '1234567890123456'));
    }

    public function testAStaleKeyFailsWhileTheTargetBranchHasNoGate(): void
    {
        $sandbox = $this->changeOnAMainWithoutTheGate(['docs/architecture/gone.md' => ['00000000000000aa' => 90]]);

        $findings = $sandbox->gateResult($this->gate(), 'main')->findings;

        self::assertSame(['ratchet/docs.json: page docs/architecture/gone.md no longer exists; remove its entry'], $findings);
    }

    public function testALooseCeilingFailsWhileTheTargetBranchHasNoGate(): void
    {
        $sandbox = $this->changeOnAMainWithoutTheGate([], ['docs/guide/long.md' => 1600]);

        $findings = $sandbox->gateResult($this->gate(), 'main')->findings;

        self::assertSame(['ratchet/docs.json: page docs/guide/long.md lists 1600 words but has 1501; lower it to 1501'], $findings);
    }

    public function testAKeyWhoseParagraphIsWithinTheLimitFails(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('docs/architecture/short.md', $this->words(10, 'short')."\n");
        $sandbox->write('ratchet/docs.json', $this->listJson([...$this->listedParagraph(), 'docs/architecture/short.md' => [$this->key($this->words(10, 'short')) => 10]]));

        self::assertSame(['ratchet/docs.json: paragraph '.$this->key($this->words(10, 'short')).' of docs/architecture/short.md has 10 words, within the limit of 80; remove its key'], $sandbox->gateResult($this->gate())->findings);
    }

    public function testALooseParagraphCeilingFails(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('ratchet/docs.json', $this->listJson([self::LISTED_PAGE => [$this->key($this->words(90, 'listed')) => 95]]));

        self::assertSame(['ratchet/docs.json: paragraph '.$this->key($this->words(90, 'listed')).' of '.self::LISTED_PAGE.' lists 95 words but has 90; lower it to 90'], $sandbox->gateResult($this->gate())->findings);
    }

    public function testAParagraphCeilingBelowItsWordsFails(): void
    {
        $sandbox = $this->listedTree();
        $sandbox->write('ratchet/docs.json', $this->listJson([self::LISTED_PAGE => [$this->key($this->words(90, 'listed')) => 85]]));

        self::assertSame([self::LISTED_PAGE.':3: paragraph of 90 words, over its ceiling of 85 words in ratchet/docs.json and the limit of 80 words per paragraph; split it or cut words to at most 85'], $sandbox->gateResult($this->gate())->findings);
    }

    public function testAPageEntryWithinThePageLimitFails(): void
    {
        $sandbox = $this->listedTree(['docs/guide/short.md' => 1600]);
        $sandbox->write('docs/guide/short.md', "Two words.\n");

        self::assertSame(['ratchet/docs.json: page docs/guide/short.md has 2 words, within the limit of 1500; remove its entry'], $sandbox->gateResult($this->gate())->findings);
    }

    public function testAPageEntryWithoutAPageLimitFails(): void
    {
        $sandbox = $this->listedTree(['docs/architecture/big.md' => 2251]);
        $sandbox->write('docs/architecture/big.md', $this->page(2251));

        self::assertSame(['ratchet/docs.json: page docs/architecture/big.md has no page limit (only pages under docs/user/ and docs/guide/ do); remove its entry'], $sandbox->gateResult($this->gate())->findings);
    }

    public function testAListThatGrewAgainstTheTargetBranchFails(): void
    {
        $sandbox = $this->changeOnAMainWithTheGate();
        $sandbox->write('docs/architecture/new.md', $this->words(81, 'fresh')."\n");
        $sandbox->write('ratchet/docs.json', $this->listJson([...$this->listedParagraph(), 'docs/architecture/new.md' => [$this->key($this->words(81, 'fresh')) => 81]]));
        $sandbox->commitAll('docs: add a page');

        self::assertSame(['ratchet/docs.json: paragraph '.$this->key($this->words(81, 'fresh')).' of docs/architecture/new.md is not on the target branch\'s list; the list may only shrink, so bring the paragraph within 80 words instead'], $sandbox->gateResult($this->gate(), 'main')->findings);
    }

    public function testARaisedPageCeilingAgainstTheTargetBranchFails(): void
    {
        $sandbox = $this->changeOnAMainWithTheGate(['docs/guide/long.md' => 1501]);
        $sandbox->write('docs/guide/long.md', $this->page(1501)."\nMore.\n");
        $sandbox->write('ratchet/docs.json', $this->listJson($this->listedParagraph(), ['docs/guide/long.md' => 1502]));
        $sandbox->commitAll('docs: grow the page');

        self::assertSame(['ratchet/docs.json: page docs/guide/long.md rose from 1501 to 1502 words; the list may only shrink, so cut the page to at most 1501 words instead'], $sandbox->gateResult($this->gate(), 'main')->findings);
    }

    public function testAListThatShrankAgainstTheTargetBranchPasses(): void
    {
        $sandbox = $this->changeOnAMainWithTheGate(['docs/guide/long.md' => 1501]);
        $sandbox->write('docs/guide/long.md', $this->page(1500));
        $sandbox->write('ratchet/docs.json', $this->listJson($this->listedParagraph()));
        $sandbox->commitAll('docs: shorten the page');

        self::assertSame([], $sandbox->gateResult($this->gate(), 'main')->findings);
    }

    public function testAMalformedListOnTheTargetBranchFails(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('ratchet/docs.json', '{"pages": {"1": 100}}');
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $this->writeListedTree($sandbox);
        $sandbox->commitAll('feat: add the gate');

        self::assertSame(['ratchet/docs.json on the target branch: malformed, so the change cannot be compared with it; fix the list on the target branch first'], $sandbox->gateResult($this->gate(), 'main')->findings);
    }

    public function testATreeWithGitButNoTargetBranchFails(): void
    {
        $sandbox = $this->changeOnAMainWithTheGate();

        self::assertSame(['no base branch found, pass --base=<ref>'], $sandbox->gateResult($this->gate(), 'missing')->findings);
    }

    /**
     * @param array<string, int> $pages
     */
    private function listedTree(array $pages = []): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $this->writeListedTree($sandbox, $pages);

        return $sandbox;
    }

    private function gate(): DocsGate
    {
        return new DocsGate(new MarkdownReader(new MarkdownCodeBlocks(new FencedCodeBlocks(), new MarkdownListIndents())));
    }

    /**
     * @param array<string, int> $pages
     */
    private function writeListedTree(Sandbox $sandbox, array $pages = []): void
    {
        $sandbox->write('README.md', "# Shop\n\nA short readme.\n");
        $sandbox->write(self::LISTED_PAGE, "# Long\n\n".$this->words(90, 'listed')."\n");
        $sandbox->write('ratchet/docs.json', $this->listJson($this->listedParagraph(), $pages));
    }

    /**
     * @param array<string, array<string, int>> $paragraphs
     * @param array<string, int>                $pages
     */
    private function changeOnAMainWithoutTheGate(array $paragraphs, array $pages = []): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "# Shop\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $this->writeListedTree($sandbox);
        $sandbox->write('docs/guide/long.md', $this->page(1501));
        $sandbox->write('ratchet/docs.json', $this->listJson([...$this->listedParagraph(), ...$paragraphs], ['docs/guide/long.md' => 1501, ...$pages]));
        $sandbox->commitAll('feat: add the docs gate');

        return $sandbox;
    }

    /**
     * @param array<string, int> $pages
     */
    private function changeOnAMainWithTheGate(array $pages = []): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $this->writeListedTree($sandbox, $pages);

        foreach (array_keys($pages) as $page) {
            $sandbox->write($page, $this->page(1501));
        }

        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        return $sandbox;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function listedParagraph(): array
    {
        return [self::LISTED_PAGE => [$this->key($this->words(90, 'listed')) => 90]];
    }

    /**
     * @param array<string, array<string, int>> $paragraphs
     * @param array<string, int>                $pages
     */
    private function listJson(array $paragraphs, array $pages = []): string
    {
        return json_encode(['paragraphs' => (object) $paragraphs, 'pages' => (object) $pages], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function key(string $text): string
    {
        return new MarkdownParagraph($text, 0, 1)->key();
    }

    private function words(int $count, string $word): string
    {
        return implode(' ', array_fill(0, $count, $word));
    }

    // Because only the page limit is under test, the words go into paragraphs that each stay within the paragraph limit.
    private function page(int $words): string
    {
        $paragraphs = array_map(static fn (array $chunk): string => implode(' ', $chunk), array_chunk(array_fill(0, $words - 1, 'step'), 79));

        return "# Page\n\n".implode("\n\n", $paragraphs)."\n";
    }
}
