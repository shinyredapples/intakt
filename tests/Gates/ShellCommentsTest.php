<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Closure;
use Intakt\Gates\LeftUndoneComments;
use Intakt\Gates\ShellComments;
use Intakt\Gates\ShellLexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShellComments::class)]
#[CoversClass(ShellLexer::class)]
#[CoversClass(LeftUndoneComments::class)]
final class ShellCommentsTest extends TestCase
{
    // Because a linear scan grows about eightfold on eight times the input and a quadratic one about sixty-fourfold, this bound leaves room for a noisy machine.
    private const float LINEAR_GROWTH_BOUND = 24.0;

    public function testAShellCommentIsReturnedFromItsHashToTheLineEnd(): void
    {
        self::assertSame([[8, '# TODO later']], new ShellComments()->commentsIn("echo a; # TODO later\necho b\n"));
    }

    /**
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function scriptsWithAMarkerComment(): iterable
    {
        yield 'a comment line' => ["#!/bin/sh\n# TODO later\n", [2 => 'TODO']];
        yield 'an indented comment' => ["if true; then\n\t# FIXME the exit code\nfi\n", [2 => 'FIXME']];
        yield 'a trailing comment after a command' => ["set -eu # xxx tighten it\n", [1 => 'xxx']];
        yield 'a comment with repeated hashes' => ["## TODO later\n", [1 => 'TODO']];
        yield 'a comment after a semicolon without a space' => ["true;# TODO later\n", [1 => 'TODO']];
        yield 'a comment after a closing parenthesis of a case pattern' => ["case \$1 in\n  start)# TODO later\n  ;;\nesac\n", [2 => 'TODO']];
        yield 'a comment after a pipe' => ["ls |# TODO later\n  wc -l\n", [1 => 'TODO']];
        yield 'a comment after a redirection' => ["exec 2>/dev/null\n: >#TODO later\n", [2 => 'TODO']];
        yield 'a comment after a closed single-quoted string' => ["echo 'a' # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a closed double-quoted string with an escaped quote' => ["echo \"a \\\" b\" # TODO later\n", [1 => 'TODO']];
        yield 'a comment after an ANSI-C string with an escaped quote' => ["echo \$'it\\'s' # TODO later\n", [1 => 'TODO']];
        yield 'a comment after an apostrophe in a quoted expansion' => ["printf '%s\\n' \"\${message:-don't stop}\"\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after a quoted opening brace in an unquoted expansion' => ["echo \${value:-'{'}\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after a quoted closing brace in an unquoted expansion' => ["echo \${value:-'}'}\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after a nested expansion in double quotes' => ["echo \"\${a:-\${b:-it's}}\" # TODO later\n", [1 => 'TODO']];
        yield 'a comment after double quotes nested in a quoted expansion' => ["echo \"\${a:-\"it's\"}\" # TODO later\n", [1 => 'TODO']];
        yield 'a comment after an ANSI-C string in a quoted expansion' => ["echo \"\${a:-\$'}'}\" # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a quoted double quote in a prefix pattern of a quoted expansion' => ["value='\"hello'\nprintf '%s\\n' \"\${value#'\"'}\"\n# TODO later\n", [3 => 'TODO']];
        yield 'a comment after a quoted double quote in a longest suffix pattern of a quoted expansion' => ["echo \"\${value%%'\"'}\"\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after a quoted double quote in a substitution pattern of a quoted expansion' => ["echo \"\${value//'\"'/x}\"\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after a quoted double quote in a pattern of a quoted array expansion' => ["echo \"\${list[1]#'\"'}\"\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after a quoted double quote in a pattern of a quoted array expansion with a variable index' => ["values=('\"hello')\ni=0\necho \"\${values[\$i]#'\"'}\"\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a quoted double quote in a pattern of a quoted array expansion with a quoted key' => ["declare -A values=([key]='\"hello')\necho \"\${values[\"key\"]#'\"'}\"\n# TODO later\n", [3 => 'TODO']];
        yield 'a comment after an apostrophe in a default nested in a quoted pattern' => ["value=\"don't stop\"\nunset prefix\nprintf '%s\\n' \"\${value#\${prefix:-don't}}\"\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after an apostrophe in a quoted default of an array expansion with a variable index' => ["echo \"\${values[\$i]:-don't}\"\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after an apostrophe in a quoted length expansion and string' => ["echo \"\${#value} don't\" # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a line continuation' => ["echo a \\\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after a multi-line string' => ["echo '\n#'\n# TODO later\n", [3 => 'TODO']];
    }

    /**
     * @param array<int, string> $markers
     */
    #[DataProvider('scriptsWithAMarkerComment')]
    public function testAMarkerInAShellCommentIsReportedOnItsGitLine(string $script, array $markers): void
    {
        self::assertSame($markers, $this->markersOn($script));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scriptsWithoutAMarkerComment(): iterable
    {
        yield 'a marker in a single-quoted string' => ["echo '# TODO later'\n"];
        yield 'a marker in a double-quoted string' => ["echo \"# TODO later\"\n"];
        yield 'a marker on a line inside a multi-line string' => ["echo \"\n# TODO later\n\"\n"];
        yield 'a marker in an ANSI-C string' => ["echo \$'\\'# TODO later'\n"];
        yield 'a hash inside a word' => ["echo a#TODO later\n"];
        yield 'a hash after an escaped space' => ["echo a\\ #TODO later\n"];
        yield 'an escaped hash' => ["echo \\# TODO later\n"];
        yield 'an escaped quote before a hash' => ["echo \\'# TODO later\n"];
        yield 'the argument count' => ["echo \$# TODO later\n"];
        yield 'a length expansion' => ["echo \${#TODO} later\n"];
        yield 'a prefix removal' => ["echo \${path# TODO later}\n"];
        yield 'a hash in a quoted expansion' => ["echo \"\${path# TODO later}\"\n"];
        yield 'a marker in single quotes inside an unquoted expansion' => ["echo \${a:-' # TODO later'}\n"];
        yield 'a marker in double quotes inside an unquoted expansion' => ["echo \${a:-\" # TODO later\"}\n"];
        yield 'a marker after a quoted brace in an unquoted expansion' => ["echo \${a:-'}' # TODO later}\n"];
        yield 'a marker after an apostrophe in a quoted expansion' => ["echo \"\${a:-don't} # TODO later\"\n"];
        yield 'a marker in a single-quoted pattern of a quoted expansion' => ["echo \"\${path#'# TODO later'}\"\n"];
        yield 'a marker after an apostrophe in a quoted default after a pattern operator inside' => ["echo \"\${a:-x#don't} # TODO later\"\n"];
        yield 'a marker in a single-quoted pattern of a quoted array expansion with a quoted key' => ["echo \"\${values[\"key\"]#'# TODO later'}\"\n"];
        yield 'a marker after an apostrophe in a quoted default of an array expansion with a variable index' => ["echo \"\${values[\$i]:-don't} # TODO later\"\n"];
        yield 'a marker after the comment start' => ["# see the TODO list\n"];
        yield 'a reasoned exception' => ["# TODO(#123): retry once the forge sends it\n"];
        yield 'a marker in a command name' => ["TODO=1 run\n"];
        yield 'an unclosed string' => ["echo '\n# TODO later\n"];
    }

    #[DataProvider('scriptsWithoutAMarkerComment')]
    public function testAShellSourceWithoutAMarkerCommentHasNoMarkers(string $script): void
    {
        self::assertSame([], $this->markersOn($script));
    }

    public function testAMarkerOnALineThatWasNotAddedIsNotReported(): void
    {
        $markers = new LeftUndoneComments()->markersOn("set -eu\n# TODO later\n", [1], new ShellComments());

        self::assertSame([], $markers);
    }

    public function testAShellScriptHoldsNoPhpCode(): void
    {
        self::assertFalse(new ShellComments()->holdsPhpCode());
    }

    public function testManyStringsAndExpansionsAreScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => str_repeat("echo \"\${a:-it's}\" '#' \${b#x} # TODO later\n", $size);

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $this->growthOf($script));
    }

    public function testDeeplyNestedExpansionsAreScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => 'echo '.str_repeat('"${a:-', $size).str_repeat('}"', $size)." # TODO later\n";

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $this->growthOf($script));
        self::assertSame([1 => 'TODO'], $this->markersOn($script(1000)));
    }

    public function testManyUnclosedSubscriptsInQuotedExpansionsAreScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => 'echo '.str_repeat('"${a[x', $size).str_repeat(']}"', $size)." # TODO later\n";

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $this->growthOf($script));
    }

    /**
     * @param Closure(int): string $script
     */
    private function growthOf(Closure $script): float
    {
        return $this->fastestScanOf($script(16000)) / $this->fastestScanOf($script(2000));
    }

    private function fastestScanOf(string $script): float
    {
        $fastest = PHP_INT_MAX;

        foreach (range(1, 5) as $ignored) {
            $started = hrtime(true);
            $this->markersOn($script);
            $fastest = min($fastest, hrtime(true) - $started);
        }

        return (float) $fastest;
    }

    /**
     * @return array<int, string>
     */
    private function markersOn(string $script): array
    {
        return new LeftUndoneComments()->markersOn($script, range(1, substr_count($script, "\n") + 1), new ShellComments());
    }
}
