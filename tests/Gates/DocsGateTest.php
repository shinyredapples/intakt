<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\DocsGate;
use Intakt\Gates\DocsRatchetList;
use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\MarkdownPage;
use Intakt\Gates\MarkdownParagraph;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(DocsGate::class)]
#[CoversClass(DocsRatchetList::class)]
#[CoversClass(MarkdownPage::class)]
#[CoversClass(MarkdownParagraph::class)]
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

    public function testAnExcusedSummaryAddedToTheListFails(): void
    {
        $sandbox = $this->startedChange([DocsRatchetList::PATH => $this->listOf([])]);
        $sandbox->write(DocsRatchetList::PATH, $this->listOf([], [], ['docs/architecture/big.md']));
        $sandbox->commitAll('docs: excuse a page');

        self::assertStringContainsString('docs/architecture/big.md added to the pages excused', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    public function testRemovedAndLoweredEntriesPass(): void
    {
        $paragraph = $this->words(120);
        $sandbox = $this->startedChange([
            DocsRatchetList::PATH => $this->listOf(['docs/user/a.md' => [$paragraph, 'b'], 'docs/user/b.md' => ['c']], ['docs/guide/big.md' => 2000], ['docs/architecture/big.md']),
            'docs/user/a.md' => "# A\n\n".$paragraph."\n",
            'docs/guide/big.md' => "# Big\n\n".$this->words(10)."\n",
        ]);
        $sandbox->write(DocsRatchetList::PATH, $this->listOf(['docs/user/a.md' => [$paragraph]], ['docs/guide/big.md' => 1800]));
        $sandbox->commitAll('docs: shrink the list');

        self::assertTrue($sandbox->runGate(new DocsGate())->passed('docs'), $sandbox->runGate(new DocsGate())->output());
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

    public function testAnInShortSectionOverTheLimitFails(): void
    {
        $sandbox = $this->changeWith(['docs/architecture/big.md' => "## Big\n\n### In short\n\n".$this->words(60)."\n\n".$this->words(61)."\n\n## Detail\n\n".$this->chapters(1600)]);

        self::assertStringContainsString('the "In short" section has 121 words, the limit is 120', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
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

    public function testWithoutGitHistoryTheLimitsStillApplyAndTheShrinkCheckIsSkipped(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('docs/user/start.md', "# Start\n\n".$this->words(81)."\n");

        self::assertTrue($sandbox->runGate(new DocsGate())->failed('docs'));

        $sandbox->write('docs/user/start.md', "# Start\n\n".$this->words(10)."\n");

        $run = $sandbox->runGate(new DocsGate());

        self::assertTrue($run->passed('docs'), $run->output());
        self::assertStringContainsString('shrink check skipped', $run->noteOf('docs'));
    }

    public function testAListThatIsNotValidFailsTheGate(): void
    {
        $sandbox = $this->changeWith([DocsRatchetList::PATH => '{"paragraphs": []}']);

        self::assertStringContainsString('ratchet/docs.json is not valid', $sandbox->runGate(new DocsGate())->findingsOf('docs'));
    }

    /**
     * @param array<string, string> $files
     */
    private function changeWith(array $files): Sandbox
    {
        $sandbox = $this->startedChange([]);

        foreach ($files as $path => $contents) {
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
     * @param array<string, list<string>> $paragraphs paragraph text by page
     * @param array<string, int>          $pages
     * @param list<string>                $summaries
     */
    private function listOf(array $paragraphs, array $pages = [], array $summaries = []): string
    {
        $keys = [];

        foreach ($paragraphs as $page => $texts) {
            $keys[$page] = array_map(static fn (string $text): string => 1 === preg_match('/^[a-f0-9]{64}$/', $text) ? $text : new MarkdownParagraph(1, $text)->key(), $texts);
        }

        return json_encode(['paragraphs' => $keys, 'pages' => $pages, 'summaries' => $summaries], JSON_THROW_ON_ERROR);
    }

    private function words(int $count): string
    {
        return trim(str_repeat('word ', $count));
    }

    // Because the page limit counts the title word too, a page of this many words plus "Long" stays under the paragraph limit in every chapter.
    private function chapters(int $words): string
    {
        return implode("\n\n", array_fill(0, intdiv($words, 50), $this->words(50)))."\n\n".$this->words($words % 50 + 1)."\n";
    }
}
