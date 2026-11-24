<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Closure;
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

    public function testAYamlCommentIsReturnedFromItsHashToTheLineEnd(): void
    {
        self::assertSame([[7, '# TODO later']], new YamlComments()->commentsIn("key: a # TODO later\nb: c\n"));
    }

    /**
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function sourcesWithAMarkerComment(): iterable
    {
        yield 'a comment line' => ["# TODO later\nkey: value\n", [1 => 'TODO']];
        yield 'an indented comment' => ["jobs:\n  # FIXME the cache key\n  check: true\n", [2 => 'FIXME']];
        yield 'a trailing comment after a plain value' => ["key: value # xxx tighten it\n", [1 => 'xxx']];
        yield 'a comment with repeated hashes' => ["## TODO later\n", [1 => 'TODO']];
        yield 'a comment after a tab' => ["key: value\t# TODO later\n", [1 => 'TODO']];
        yield 'a comment after an empty value' => ["key: # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a single-quoted value with an escaped quote' => ["key: 'it''s' # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a double-quoted value with an escaped quote' => ["key: \"a \\\" b\" # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a multi-line double-quoted value' => ["key: \"a\n  # b\"\n# TODO later\n", [3 => 'TODO']];
        yield 'a comment after a quoted key' => ["'a b': c # TODO later\n", [1 => 'TODO']];
        yield 'a comment after an apostrophe in a plain value' => ["key: don't stop\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after an apostrophe in a plain sequence entry' => ["- it's here\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after a flow sequence with a quoted entry' => ["list: [a, '#', \"b\"] # TODO later\n", [1 => 'TODO']];
        yield 'a comment on a block scalar header' => ["script: | # TODO later\n  echo a\n", [1 => 'TODO']];
        yield 'a comment after a literal block scalar' => ["script: |\n  echo a\n  # a line of the script\nnext: 1 # TODO later\n", [4 => 'TODO']];
        yield 'a comment after a folded block scalar with chomping' => ["text: >-\n  a # b\n\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment indented less than the block body' => ["script: |\n    echo a\n  # TODO later\n", [3 => 'TODO']];
        yield 'a comment after a block scalar in a sequence entry mapping' => ["- run: |\n    echo a\n    # a line of the script\n  # TODO later\n", [4 => 'TODO']];
        yield 'a comment after a block scalar as a sequence entry' => ["- |\n  # a line of the text\n# TODO later\n", [3 => 'TODO']];
        yield 'a comment after a block scalar with an indentation indicator' => ["key: |2\n    # a line of the text\n# TODO later\n", [3 => 'TODO']];
        yield 'a comment after a block scalar on the line after its key' => ["key:\n  |\n  # a line of the text\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a block scalar behind an anchor before a compact key' => ["- &id key: |\n    echo a\n  other: 1 # TODO later\n", [3 => 'TODO']];
        yield 'a comment after a block scalar with a tag and an anchor before its indicator' => ["key: !!str &text |\n  # a line of the text\n# TODO later\n", [3 => 'TODO']];
        yield 'a comment after an empty block scalar' => ["key: |\n# TODO later\n", [2 => 'TODO']];
    }

    /**
     * @param array<int, string> $markers
     */
    #[DataProvider('sourcesWithAMarkerComment')]
    public function testAMarkerInAYamlCommentIsReportedOnItsGitLine(string $source, array $markers): void
    {
        self::assertSame($markers, $this->markersOn($source));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sourcesWithoutAMarkerComment(): iterable
    {
        yield 'a marker in a single-quoted value' => ["key: '# TODO later'\n"];
        yield 'a marker in a double-quoted value' => ["key: \"# TODO later\"\n"];
        yield 'a marker on a line inside a multi-line double-quoted value' => ["key: \"a\n  # TODO later\"\n"];
        yield 'a marker on a line inside a multi-line single-quoted value' => ["key: 'it''s\n  # TODO later'\n"];
        yield 'a hash inside a plain value' => ["url: a#TODO later\n"];
        yield 'a hash inside a key' => ["C#TODO: later\n"];
        yield 'a marker in a quoted entry of a flow sequence' => ["list: [a, '# TODO later']\n"];
        yield 'a marker in a literal block scalar' => ["script: |\n  # TODO later\n"];
        yield 'a marker in a folded block scalar after a blank line' => ["text: >\n  a\n\n  # TODO later\n"];
        yield 'a marker in a block scalar with keep chomping and an indentation indicator' => ["text: |+2\n  # TODO later\n"];
        yield 'a marker in a block scalar behind an anchor before a compact key' => ["- &id key: |\n    # TODO later\n"];
        yield 'a marker in a block scalar behind a tag before a compact key' => ["- !tag key: >\n    # TODO later\n"];
        yield 'a marker in a block scalar behind an anchor and a tag before a compact key' => ["- &id !!str key: |\n    # TODO later\n"];
        yield 'a marker in a block scalar of a nested sequence entry' => ["- - key: |\n      # TODO later\n"];
        yield 'a marker after the comment start' => ["# see the TODO list\n"];
        yield 'a reasoned exception' => ["# TODO(#123): drop once the forge sends it\n"];
        yield 'a marker as a key' => ["TODO: later\n"];
        yield 'an unclosed single-quoted value' => ["key: '\n# TODO later\n"];
        yield 'an unclosed double-quoted value' => ["key: \"\\\"\n# TODO later\n"];
    }

    #[DataProvider('sourcesWithoutAMarkerComment')]
    public function testAYamlSourceWithoutAMarkerCommentHasNoMarkers(string $source): void
    {
        self::assertSame([], $this->markersOn($source));
    }

    public function testAMarkerOnALineThatWasNotAddedIsNotReported(): void
    {
        $markers = new LeftUndoneComments()->markersOn("key: value\n# TODO later\n", [1], new YamlComments());

        self::assertSame([], $markers);
    }

    public function testAYamlFileHoldsNoPhpCode(): void
    {
        self::assertFalse(new YamlComments()->holdsPhpCode());
    }

    public function testManyQuotedValuesAndBlockScalarsAreScannedInLinearTime(): void
    {
        $source = static fn (int $size): string => str_repeat("- &id key: 'it''s' # TODO later\n  run: |\n    echo \"#\"\n  list: [a, \"b\"] # x\n", $size);

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $this->growthOf($source));
    }

    /**
     * @param Closure(int): string $source
     */
    private function growthOf(Closure $source): float
    {
        return $this->fastestScanOf($source(16000)) / $this->fastestScanOf($source(2000));
    }

    private function fastestScanOf(string $source): float
    {
        $fastest = PHP_INT_MAX;

        foreach (range(1, 5) as $ignored) {
            $started = hrtime(true);
            $this->markersOn($source);
            $fastest = min($fastest, hrtime(true) - $started);
        }

        return (float) $fastest;
    }

    /**
     * @return array<int, string>
     */
    private function markersOn(string $source): array
    {
        return new LeftUndoneComments()->markersOn($source, range(1, substr_count($source, "\n") + 1), new YamlComments());
    }
}
