<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FencedCodeBlocks;
use Intakt\Gates\MarkdownCodeBlocks;
use Intakt\Gates\MarkdownComments;
use Intakt\Gates\MarkdownDelimiterRow;
use Intakt\Gates\MarkdownLineKind;
use Intakt\Gates\MarkdownLineKinds;
use Intakt\Gates\MarkdownListIndents;
use Intakt\Gates\MarkdownPage;
use Intakt\Gates\MarkdownParagraph;
use Intakt\Gates\MarkdownReader;
use Intakt\Gates\MarkdownReadFailure;
use Intakt\Gates\MarkdownSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(MarkdownReader::class)]
#[CoversClass(MarkdownReadFailure::class)]
#[CoversClass(MarkdownCodeBlocks::class)]
#[UsesClass(MarkdownListIndents::class)]
#[UsesClass(MarkdownDelimiterRow::class)]
#[UsesClass(FencedCodeBlocks::class)]
#[UsesClass(MarkdownPage::class)]
#[UsesClass(MarkdownParagraph::class)]
#[UsesClass(MarkdownLineKind::class)]
#[UsesClass(MarkdownLineKinds::class)]
#[UsesClass(MarkdownComments::class)]
#[UsesClass(MarkdownSummary::class)]
final class MarkdownReaderTest extends TestCase
{
    private string $root;

    public function testItReadsOnePagePerMarkdownFileUnderDocsAndTheReadmeWithItsWordCount(): void
    {
        $pages = $this->reader()->pages($this->root, ['src/README.md', 'docs/user/guide.md', 'docs/notes.txt', 'README.md']);

        self::assertSame(['README.md', 'docs/user/guide.md'], array_map(static fn (MarkdownPage $page): string => $page->path, $pages));
        self::assertSame([4, 2], array_map(static fn (MarkdownPage $page): int => $page->words(), $pages));
    }

    public function testFencedCodeIsNotCounted(): void
    {
        new Filesystem()->dumpFile($this->root.'/docs/fenced.md', "Before.\n\n```php\necho 'not counted';\n```\n\nAfter.\n");

        $page = $this->reader()->pages($this->root, ['docs/fenced.md'])[0];

        self::assertSame(['Before.', 'After.'], array_map(static fn (MarkdownParagraph $paragraph): string => $paragraph->text, $page->paragraphs()));
    }

    public function testAFenceInsideAMultilineCommentOpensNoCodeBlock(): void
    {
        new Filesystem()->dumpFile($this->root.'/docs/commented.md', "# Title\n\n<!--\n```\n-->\n\n## In short\n\nOverview.\n\n```text\nexample\n```\n");

        $page = $this->reader()->pages($this->root, ['docs/commented.md'])[0];

        self::assertEquals(new MarkdownSummary(1, true, true), $page->summary());
    }

    public function testACommentOpenerInsideAFenceOpensNoComment(): void
    {
        new Filesystem()->dumpFile($this->root.'/docs/fenced-comment.md', "Before.\n\n```\n<!--\n```\n\nAfter.\n");

        $page = $this->reader()->pages($this->root, ['docs/fenced-comment.md'])[0];

        self::assertSame(['Before.', 'After.'], array_map(static fn (MarkdownParagraph $paragraph): string => $paragraph->text, $page->paragraphs()));
    }

    public function testACommentClosedOnItsOpeningLineLetsTheNextFenceOpen(): void
    {
        new Filesystem()->dumpFile($this->root.'/docs/one-line-comment.md', "<!-- note -->\n```\ncode words\n```\n\nAfter.\n");

        $page = $this->reader()->pages($this->root, ['docs/one-line-comment.md'])[0];

        self::assertSame(['<!-- note -->', 'After.'], array_map(static fn (MarkdownParagraph $paragraph): string => $paragraph->text, $page->paragraphs()));
    }

    public function testFencedCodeNestedInAListIsNotCounted(): void
    {
        new Filesystem()->dumpFile($this->root.'/docs/listed.md', "- Item.\n  - Nested.\n    ```\n    code words\n    ```\n\nAfter.\n");

        $page = $this->reader()->pages($this->root, ['docs/listed.md'])[0];

        self::assertSame(['Item.', 'Nested.', 'After.'], array_map(static fn (MarkdownParagraph $paragraph): string => $paragraph->text, $page->paragraphs()));
        self::assertSame(3, $page->words());
    }

    public function testAFourSpaceFenceOutsideAListIsIndentedCodeAndBlanksNothingAfterIt(): void
    {
        new Filesystem()->dumpFile($this->root.'/docs/indented.md', "Before.\n\n    ```\n    code\n\nAfter.\n");

        $page = $this->reader()->pages($this->root, ['docs/indented.md'])[0];

        self::assertSame(['Before.', 'After.'], array_map(static fn (MarkdownParagraph $paragraph): string => $paragraph->text, $page->paragraphs()));
    }

    public function testAnUnclosedFenceInANestedListItemEndsWithTheList(): void
    {
        new Filesystem()->dumpFile($this->root.'/docs/unclosed.md', "- Item.\n  - Nested.\n    ```\n    code words\n\nAfter.\n");

        $page = $this->reader()->pages($this->root, ['docs/unclosed.md'])[0];

        self::assertSame(['Item.', 'Nested.', 'After.'], array_map(static fn (MarkdownParagraph $paragraph): string => $paragraph->text, $page->paragraphs()));
    }

    public function testAnUnclosedFenceIndentedPastTheItemsContentEndsWithTheList(): void
    {
        new Filesystem()->dumpFile($this->root.'/docs/deeper.md', "- Item.\n    ```\n    code\n\nAfter words here.\n");

        $page = $this->reader()->pages($this->root, ['docs/deeper.md'])[0];

        self::assertSame(['Item.', 'After words here.'], array_map(static fn (MarkdownParagraph $paragraph): string => $paragraph->text, $page->paragraphs()));
    }

    public function testAFenceOpenedOnTheListMarkerLineIsNotCounted(): void
    {
        new Filesystem()->dumpFile($this->root.'/docs/marker.md', "- ```\n  code words\n  ```\n\nAfter.\n");

        $page = $this->reader()->pages($this->root, ['docs/marker.md'])[0];

        self::assertSame(['After.'], array_map(static fn (MarkdownParagraph $paragraph): string => $paragraph->text, $page->paragraphs()));
        self::assertSame(1, $page->words());
    }

    public function testAFencedBlockBeforeTheInShortHeadingMeansTheSummaryDoesNotOpenThePage(): void
    {
        new Filesystem()->dumpFile($this->root.'/docs/example.md', "# Title\n\n```\nexample\n```\n\n## In short\nSummary.\n");

        $page = $this->reader()->pages($this->root, ['docs/example.md'])[0];

        self::assertFalse($page->summary()?->opensPage);
    }

    public function testAListItemLineInsideAFenceStartsNoItem(): void
    {
        new Filesystem()->dumpFile($this->root.'/docs/inside.md', "- Item.\n  ```\n  - not an item\n  more code\n  ```\n\nAfter.\n");

        $page = $this->reader()->pages($this->root, ['docs/inside.md'])[0];

        self::assertSame(['Item.', 'After.'], array_map(static fn (MarkdownParagraph $paragraph): string => $paragraph->text, $page->paragraphs()));
    }

    public function testAMissingPageFailsTheReader(): void
    {
        $this->expectExceptionObject(new MarkdownReadFailure('cannot read docs/gone.md'));

        $this->reader()->pages($this->root, ['docs/gone.md']);
    }

    public function testAnUnreadablePageFailsTheReader(): void
    {
        new Filesystem()->chmod($this->root.'/docs/user/guide.md', 0o000);

        $this->expectExceptionObject(new MarkdownReadFailure('cannot read docs/user/guide.md'));

        $this->reader()->pages($this->root, ['docs/user/guide.md']);
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/markdown-reader-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->root.'/README.md', "# Intakt\n\nA loop runner.\n");
        new Filesystem()->dumpFile($this->root.'/docs/user/guide.md', "Two words.\n");
        new Filesystem()->dumpFile($this->root.'/docs/notes.txt', "Not Markdown.\n");
        new Filesystem()->dumpFile($this->root.'/src/README.md', "Not measured.\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->chmod($this->root, 0o755, recursive: true);
        new Filesystem()->remove($this->root);
    }

    private function reader(): MarkdownReader
    {
        return new MarkdownReader(new MarkdownCodeBlocks(new FencedCodeBlocks(), new MarkdownListIndents()));
    }
}
