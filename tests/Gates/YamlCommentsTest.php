<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\LeftUndoneComments;
use Intakt\Gates\YamlComments;
use Intakt\Gates\YamlLexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(YamlComments::class)]
#[CoversClass(YamlLexer::class)]
#[CoversClass(LeftUndoneComments::class)]
final class YamlCommentsTest extends TestCase
{
    // Because a linear scan grows about eightfold on eight times the input and a quadratic one about sixty-fourfold, this bound leaves room for a noisy machine.
    private const float LINEAR_GROWTH_BOUND = 24.0;

    /**
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function documentsWithAMarkerComment(): iterable
    {
        yield 'a comment line' => ["# TODO name the service\nkey: 1\n", [1 => 'TODO']];
        yield 'a trailing comment after a value' => ["key: 1 # fixme: raise it\n", [1 => 'fixme']];
        yield 'a comment without a space after the hash' => ["key: 1 #XXX later\n", [1 => 'XXX']];
        yield 'an indented comment' => ["a:\n  # TODO later\n  b: 1\n", [2 => 'TODO']];
        yield 'a repeated hash' => ["## TODO later\n", [1 => 'TODO']];
        yield 'a comment after a single-quoted value with an escaped quote' => ["key: 'it''s # x' # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a double-quoted value with an escaped quote' => ["key: \"a \\\" # x\" # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a plain value holding a quote' => ["key: it's # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a sequence entry' => ["- a # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a quoted sequence entry' => ["- '# x' # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a flow sequence' => ["key: ['# a', \"# b\"] # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a flow mapping with touching colons' => ["key: {\"a\":\"# b\"} # TODO later\n", [1 => 'TODO']];
        yield 'a comment on the line of a block scalar header' => ["key: | # TODO later\n  text\n", [1 => 'TODO']];
        yield 'a comment after a block scalar' => ["key: |\n  # inside\n  text\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a block scalar in a sequence' => ["- a: >-\n    # inside\n  b: 1 # TODO later\n", [3 => 'TODO']];
        yield 'a comment after a block scalar with blank lines' => ["key: |\n  a\n\n  b\nnext: 1 # TODO later\n", [5 => 'TODO']];
        yield 'a comment after an anchor and a tag' => ["key: &a !!str x # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a document marker' => ["--- # TODO later\nkey: 1\n", [1 => 'TODO']];
        yield 'a comment after a multi-line quoted value' => ["key: \"a\n  # x\n  b\" # TODO later\n", [3 => 'TODO']];
        yield 'a comment after carriage return line ends' => ["key: 1\r\n# TODO later\r\n", [2 => 'TODO']];
        yield 'a comment after an empty block scalar in a compact mapping' => ["- key: |\n  # TODO real comment\n  next: value\n", [2 => 'TODO']];
        yield 'a comment after a block scalar with an explicit indentation' => ["key: |2\n    text\n  more\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a plain value continued by a line starting with a quote' => ["key: plain text\n  'continued # TODO later\n", [2 => 'TODO']];
        yield 'a comment after a plain sequence entry continued by a line starting with a quote' => ["- plain\n  \"continued # TODO later\n", [2 => 'TODO']];
        yield 'a comment after a quoted value on the next mapping line' => ["a: plain\nb: '# x' # TODO later\n", [2 => 'TODO']];
        yield 'a comment after a flow entry whose colon touches a quote' => ["key: [a:'b, c] # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a flow mapping with a colon before a flow indicator' => ["key: {a:, b: '# x'} # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a block scalar of an anchored compact mapping key' => ["- &id key: |\n    text\n  next: 1 # TODO later\n", [3 => 'TODO']];
        yield 'a comment in a mapping under an anchored key' => ["key: &a\n  sub: |\n    text\n  # TODO later\n", [4 => 'TODO']];
        yield 'a comment on the first line after a byte order mark' => ["\u{FEFF}# TODO later\nkey: 1\n", [1 => 'TODO']];
    }

    /**
     * @param array<int, string> $markers
     */
    #[DataProvider('documentsWithAMarkerComment')]
    public function testAMarkerInAYamlCommentIsReportedOnItsGitLine(string $document, array $markers): void
    {
        self::assertSame($markers, $this->markersOn($document));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentsWithoutAMarkerComment(): iterable
    {
        yield 'a marker in a single-quoted value' => ["key: '# TODO later'\n"];
        yield 'a marker in a double-quoted value' => ["key: \"x # TODO later\"\n"];
        yield 'a marker in a quoted value over two lines' => ["key: 'a\n  # TODO later'\n"];
        yield 'a marker in a quoted key' => ["'# TODO later': 1\n"];
        yield 'a marker after a hash inside a plain value' => ["key: a#TODO\n"];
        yield 'a marker after a hash inside a variable' => ["run: echo \${#TODO}\n"];
        yield 'a marker in a literal block scalar' => ["key: |\n  # TODO later\n"];
        yield 'a marker in a folded block scalar with indicators' => ["key: >2-\n    # TODO later\n"];
        yield 'a marker in a block scalar sequence entry' => ["- |\n  # TODO later\n"];
        yield 'a marker in a block scalar with an explicit indentation below its first line' => ["key: |2\n    text\n  # TODO scalar text\n"];
        yield 'a marker in a block scalar of a compact mapping' => ["- key: |\n    # TODO later\n  next: value\n"];
        yield 'a marker in a quoted value on a line nested under a key' => ["a:\n  b: '# TODO later'\n"];
        yield 'a marker in a quoted flow entry' => ["key: [a, '# TODO later']\n"];
        yield 'a marker in a block scalar after bare carriage returns' => ["- |\r  # TODO later\r"];
        yield 'a marker in a block scalar after carriage return line ends' => ["- |\r\n  # TODO later\r\n"];
        yield 'a marker in a quoted flow value after a touching quoted key' => ["key: {\"a\":'# TODO later'}\n"];
        yield 'a marker after the comment start' => ["# see the TODO list\n"];
        yield 'a reasoned exception' => ["key: 1 # TODO(#123): drop once the image is pinned\n"];
        yield 'a marker in a value' => ["TODO: 1\n"];
        yield 'a marker in a block scalar of an anchored compact mapping key' => ["- &id key: |\n    # TODO scalar text\n  next: 1\n"];
        yield 'a marker in a block scalar of a tagged compact mapping key' => ["- !tag key: |\n    # TODO scalar text\n  next: 1\n"];
    }

    #[DataProvider('documentsWithoutAMarkerComment')]
    public function testAYamlSourceWithoutAMarkerCommentHasNoMarkers(string $document): void
    {
        self::assertSame([], $this->markersOn($document));
    }

    public function testCommentsComeBackWithTheirOffsetsAndTheirHashes(): void
    {
        self::assertSame([[0, '# one'], [13, '# two'], [19, '#three']], new YamlComments()->commentsIn("# one\nkey: 1 # two\n#three"));
    }

    public function testAnUnclosedQuoteOrBlockScalarEndsTheScanWithoutAComment(): void
    {
        foreach (["key: '\n# TODO", "key: \"\n# TODO", 'key: "\\', "key: |\n  # TODO", 'key: [a'] as $document) {
            self::assertSame([], $this->markersOn($document), $document);
        }
    }

    public function testAMarkerOnALineThatWasNotAddedIsNotReported(): void
    {
        $markers = new LeftUndoneComments()->markersOn("key: 1\n# TODO later\n", [1], new YamlComments());

        self::assertSame([], $markers);
    }

    public function testManyCommentsQuotesAndBlockScalarsAreScannedInLinearTime(): void
    {
        $document = static fn (int $size): string => str_repeat("- a: '#' # b\n  c: [\"#\", d] # e\n  f: |\n    # g\n", $size);

        $growth = $this->fastestScanOf($document(16000)) / $this->fastestScanOf($document(2000));

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $growth);
    }

    private function fastestScanOf(string $document): float
    {
        $fastest = PHP_INT_MAX;

        foreach (range(1, 5) as $ignored) {
            $started = hrtime(true);
            $this->markersOn($document);
            $fastest = min($fastest, hrtime(true) - $started);
        }

        return (float) $fastest;
    }

    /**
     * @return array<int, string>
     */
    private function markersOn(string $document): array
    {
        return new LeftUndoneComments()->markersOn($document, range(1, substr_count($document, "\n") + 1), new YamlComments());
    }
}
