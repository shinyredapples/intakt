<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Closure;
use Intakt\Gates\LeftUndoneComments;
use Intakt\Gates\ShellComments;
use Intakt\Gates\ShellHereDocuments;
use Intakt\Gates\ShellLexer;
use Intakt\Gates\ShellLiterals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShellComments::class)]
#[CoversClass(ShellLexer::class)]
#[CoversClass(ShellLiterals::class)]
#[CoversClass(ShellHereDocuments::class)]
#[CoversClass(LeftUndoneComments::class)]
final class ShellCommentsTest extends TestCase
{
    // Because a linear scan grows about eightfold on eight times the input and a quadratic one about sixty-fourfold, this bound leaves room for a noisy machine.
    private const float LINEAR_GROWTH_BOUND = 24.0;

    /**
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function scriptsWithAMarkerComment(): iterable
    {
        yield 'a whole-line comment' => ["# TODO check the exit code\n", [1 => 'TODO']];
        yield 'a comment after a command' => ["echo done # FIXME quote it\n", [1 => 'FIXME']];
        yield 'an indented comment' => ["if true; then\n    # XXX later\nfi\n", [2 => 'XXX']];
        yield 'a lower-case marker' => ["# todo: later\n", [1 => 'todo']];
        yield 'a nested comment marker' => ["# # TODO later\n", [1 => 'TODO']];
        yield 'repeated hashes' => ["## TODO later\n", [1 => 'TODO']];
        yield 'a comment right after a semicolon' => ["true;# TODO later\n", [1 => 'TODO']];
        yield 'a comment right after a pipe' => ["true |# TODO later\n", [1 => 'TODO']];
        yield 'a comment after a subshell' => ["(cd /) # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a quoted hash' => ["echo '#' # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a double-quoted string with an escaped quote' => ["echo \"a\\\"#\" # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a string over two lines' => ["echo 'a\n# TODO inside' # FIXME later\n", [2 => 'FIXME']];
        yield 'a comment after an ANSI-C string with an escaped quote' => ["echo \$'a\\'#' # TODO later\n", [1 => 'TODO']];
        yield 'a comment inside a command substitution' => ["x=\$(\n  # TODO later\n  date)\n", [2 => 'TODO']];
        yield 'a comment inside a substitution in a string' => ["x=\"\$(\n  # TODO later\n  date)\"\n", [2 => 'TODO']];
        yield 'a comment after a substitution in a string' => ["x=\"\$(date '#')#\" # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a parameter length' => ["n=\${#items[@]} # TODO later\n", [1 => 'TODO']];
        yield 'a comment after arithmetic' => ["n=\$(( 16#ff << 1 )) # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a here-string' => ["read -r a <<< \"\$b\" # TODO later\n", [1 => 'TODO']];
        yield 'a comment on the line of a here-document operator' => ["cat <<EOF # TODO later\nbody\nEOF\n", [1 => 'TODO']];
        yield 'a comment after a here-document' => ["cat <<EOF\n# TODO inside\nEOF\n# FIXME later\n", [4 => 'FIXME']];
        yield 'a comment after a quoted here-document' => ["cat <<'EOF'\n# TODO \$inside\nEOF\n# FIXME later\n", [4 => 'FIXME']];
        yield 'a comment after a here-document with an ANSI-C delimiter' => ["cat <<\$'EOF'\n# TODO inside\nEOF\n# FIXME later\n", [4 => 'FIXME']];
        yield 'a comment after a tab-stripping here-document' => ["cat <<-EOF\n\t# TODO inside\n\tEOF\n# FIXME later\n", [4 => 'FIXME']];
        yield 'a comment after two here-documents on one line' => ["cat <<A <<B\nA\n# TODO inside\nB\n# FIXME later\n", [5 => 'FIXME']];
        yield 'a comment after a here-document in a substitution' => ["x=\$(cat <<EOF\n)\nEOF\n)\n# FIXME later\n", [5 => 'FIXME']];
        yield 'a comment after arithmetic in a function body' => ["f() { (( x <<= 1 )); }\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after arithmetic in a keyword function body' => ["function f { (( x <<= 1 )); }\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after arithmetic in a loop head' => ["while (( x <<= 1 )); do :; done\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after arithmetic after a control operator' => ["true && (( x <<= 1 ))\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after a case pattern' => ["case \$a in\n  b) # TODO later\n    ;;\nesac\n", [2 => 'TODO']];
        yield 'a comment after a line continuation' => ["echo a \\\n  # TODO later\n", [2 => 'TODO']];
        yield 'a comment after carriage return line ends' => ["true\r\n# TODO later\r\n", [2 => 'TODO']];
        yield 'a comment after a single-quoted brace in a parameter default' => ["value=\${value:-'{'}\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after a double-quoted brace in a parameter default' => ["value=\${value:-\"{\"}\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after a nested parameter default' => ["value=\${value:-\${other:-'{'}}\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment in a case arm in a substitution in a string' => ["value=\"\$(\n    case \"\$1\" in\n        a) # TODO later\n            printf '%s\\n' a ;;\n    esac\n)\"\n# FIXME later\n", [3 => 'TODO', 7 => 'FIXME']];
        yield 'a comment after a case with a parenthesized pattern in a substitution' => ["x=\$(case \$1 in (a) echo a;; esac) # TODO later\n", [1 => 'TODO']];
        yield 'a comment in a substitution on the line of a here-document operator' => ["cat <<EOF \"\$(\n    # TODO later\n    printf '%s\\n' input\n)\"\n# TODO inside\nEOF\n# FIXME later\n", [2 => 'TODO', 7 => 'FIXME']];
        yield 'a comment between nested here-documents' => ["cat <<EOF \"\$(\n    cat <<EOF\n# TODO inside\nEOF\n    # TODO later\n)\"\n# TODO outer\nEOF\n# FIXME later\n", [5 => 'TODO', 9 => 'FIXME']];
        yield 'a comment after a here-document opened in a subshell' => ["(cat <<EOF)\n# TODO inside\nEOF\n# FIXME later\n", [4 => 'FIXME']];
        yield 'a comment after a here-document piped into a subshell' => ["cat <<EOF | (\n# TODO this is body text\nEOF\ncat\n)\n# FIXME later\n", [6 => 'FIXME']];
        yield 'a comment after a here-document before a subshell in a list' => ["cat <<EOF && (\n# TODO this is body text\nEOF\ntrue\n)\n# FIXME later\n", [6 => 'FIXME']];
        yield 'a comment after a here-document piped into a case' => ["cat <<EOF | case x in\n# TODO this is body text\nEOF\nx) cat ;;\nesac\n# FIXME later\n", [6 => 'FIXME']];
        yield 'a comment after a here-document piped into a group' => ["cat <<EOF | {\n# TODO this is body text\nEOF\ncat\n}\n# FIXME later\n", [6 => 'FIXME']];
        yield 'a comment after a here-document opened inside a subshell over lines' => ["(\ncat <<EOF\n# TODO inside\nEOF\n# XXX later\n)\n", [5 => 'XXX']];
        yield 'a comment after a plain brace in a parameter default' => ["printf '<%s>\\n' \"\${v:-{}\"\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment in a substitution in a parameter default' => ["value=\"\${value:-\$(\n    # TODO remove fallback\n    printf '%s' fallback\n)}\"\n# FIXME later\n", [2 => 'TODO', 5 => 'FIXME']];
        yield 'a comment in a substitution in an unquoted parameter default' => ["value=\${value:-\$(\n    # TODO remove fallback\n    date)} # FIXME later\n", [2 => 'TODO', 3 => 'FIXME']];
        yield 'a comment in a substitution in a string in a parameter default' => ["value=\"\${a:-\"\$(date # TODO later\n)\"}\" # FIXME later\n", [1 => 'TODO', 2 => 'FIXME']];
        yield 'a comment after a case whose pattern is the word case' => ["x=\"\$(case x in\n  case) :;;\nesac)\"\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a case whose pattern list starts with the word case' => ["case x in\n  case | y) (true) ;;\nesac\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after an apostrophe in a parameter default in a string' => ["printf '%s\\n' \"\${message:-don't stop}\"\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after an apostrophe in a nested parameter default in a string' => ["echo \"\${a:-\${b:-it's}}\"\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after a quoted default in a substitution in a string' => ["echo \"\$(echo \${a:-'}'})\"\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment in a case nested in a case arm' => ["case x in\n  x) case y in\n       y) # TODO later\n          ;;\n     esac ;;\nesac\n", [3 => 'TODO']];
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
        yield 'a marker in single quotes' => ["echo '# TODO later'\n"];
        yield 'a marker in double quotes' => ["echo \"# TODO later\"\n"];
        yield 'a marker in an ANSI-C string' => ["echo \$'# TODO later'\n"];
        yield 'a marker in a locale string' => ["echo \$\"# TODO later\"\n"];
        yield 'a marker after an escaped hash' => ["echo \\# TODO later\n"];
        yield 'a hash inside a word' => ["echo a#TODO\n"];
        yield 'a hash after an equals sign' => ["x=#TODO\n"];
        yield 'the number of positional parameters' => ["echo \$# TODO later\n"];
        yield 'a parameter length' => ["echo \${#TODO}\n"];
        yield 'a prefix removal' => ["echo \${x#TODO}\n"];
        yield 'a nested parameter expansion' => ["echo \${x:-\${y#TODO}}\n"];
        yield 'an arithmetic base' => ["echo \$(( 16#TODO ))\n"];
        yield 'a hash after a substitution' => ["echo \$(date)#TODO\n"];
        yield 'a marker in backticks' => ["echo `echo '#' TODO`\n"];
        yield 'a marker in a here-document' => ["cat <<EOF\n# TODO inside\nEOF\n"];
        yield 'a marker in a quoted here-document' => ["cat <<\"EOF\"\n# TODO inside\nEOF\n"];
        yield 'a marker in a tab-stripping here-document' => ["cat <<-EOF\n\t# TODO inside\n\tEOF\n"];
        yield 'a marker in an ANSI-C delimited here-document' => ["cat <<\$'EOF'\n# TODO inside\nEOF\n"];
        yield 'a marker after an escaped line end in a here-document' => ["cat <<EOF\nprefix\\\nEOF\n# TODO inside\nEOF\n"];
        yield 'a marker in an unclosed here-document' => ["cat <<EOF\n# TODO inside\n"];
        yield 'a marker in a here-document in a substitution' => ["x=\$(cat <<EOF\n# TODO inside\nEOF\n)\n"];
        yield 'a marker after a single-quoted closing brace in a parameter default' => ["value=\${value:-'} # TODO literal'}\n"];
        yield 'a marker after a double-quoted closing brace in a parameter default' => ["value=\${value:-\"} # TODO literal\"}\n"];
        yield 'a marker after a hash in a parameter default in a string' => ["echo \"\${x:-a # TODO literal}\"\n"];
        yield 'a marker after a string that ends in a parameter default' => ["echo \${x:-\"a\" # TODO literal\n}\n"];
        yield 'a hash in a shebang' => ["#!/bin/sh\n"];
        yield 'a reasoned exception' => ["# TODO(#123): drop once the image ships curl\n"];
    }

    #[DataProvider('scriptsWithoutAMarkerComment')]
    public function testAShellSourceWithoutAMarkerCommentHasNoMarkers(string $script): void
    {
        self::assertSame([], $this->markersOn($script));
    }

    public function testAnEscapedLineEndInAQuotedHereDocumentDoesNotJoinTheDelimiterLine(): void
    {
        self::assertSame([4 => 'TODO'], $this->markersOn("cat <<'EOF'\nprefix\\\nEOF\n# TODO later\n"));
    }

    public function testAnEvenRunOfBackslashesDoesNotJoinTheDelimiterLine(): void
    {
        self::assertSame([4 => 'TODO'], $this->markersOn("cat <<EOF\nprefix\\\\\nEOF\n# TODO later\n"));
    }

    public function testAMarkerOnALineThatWasNotAddedIsNotReported(): void
    {
        $markers = new LeftUndoneComments()->markersOn("true\n# TODO later\n", [1], new ShellComments());

        self::assertSame([], $markers);
    }

    public function testAnUnclosedQuoteOrExpansionEndsTheScanWithoutAComment(): void
    {
        foreach (["'# TODO", '"# TODO', "\$'# TODO", '${# TODO', '$(( # TODO', '`# TODO', 'a\\'] as $script) {
            self::assertSame([], $this->markersOn($script), $script);
        }
    }

    public function testManyJoinedLinesOfAHereDocumentAreScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => "cat <<EOF\n".str_repeat("line\\\n", $size)."\nEOF\n# TODO later\n";

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $this->growthOf($script));
        self::assertSame([1004 => 'TODO'], $this->markersOn($script(1000)));
    }

    public function testALongDelimiterWithManyJoinedLinesIsScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => 'cat <<'.str_repeat('X', $size)."\n".str_repeat("x\\\n", $size)."\n".str_repeat('X', $size)."\n# TODO later\n";

        // Because copying the kept text of each joined line only shows once that text outgrows the processor's caches.
        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $this->growthOf($script, 32000));
        self::assertSame([1004 => 'TODO'], $this->markersOn($script(1000)));
    }

    public function testDeeplyNestedSubstitutionsAndStringsAreScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => 'x='.str_repeat('"$(', $size).'# TODO'."\n".str_repeat(')"', $size)."\n";

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $this->growthOf($script));
        self::assertSame([1 => 'TODO'], $this->markersOn($script(1000)));
    }

    public function testManyHereDocumentsInSubstitutionsOnOneLineAreScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => 'x='.str_repeat('"$(cat <<EOF)"', $size)."\n".str_repeat("EOF\n", $size)."# TODO later\n";

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $this->growthOf($script));
        self::assertSame([1002 => 'TODO'], $this->markersOn($script(1000)));
    }

    /**
     * @param Closure(int): string $script
     */
    private function growthOf(Closure $script, int $size = 2000): float
    {
        return $this->fastestScanOf($script(8 * $size)) / $this->fastestScanOf($script($size));
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
