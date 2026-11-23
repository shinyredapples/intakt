<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\LeftUndoneComments;
use Intakt\Gates\ShellComments;
use Intakt\Gates\ShellHereDocuments;
use Intakt\Gates\ShellLexer;
use Intakt\Gates\ShellQuotes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShellComments::class)]
#[CoversClass(ShellLexer::class)]
#[CoversClass(ShellHereDocuments::class)]
#[CoversClass(ShellQuotes::class)]
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
        yield 'a comment line' => ["#!/bin/sh\n# TODO finish the deploy\n", [2 => 'TODO']];
        yield 'a trailing comment after a command' => ["make build # fixme: cache it\n", [1 => 'fixme']];
        yield 'a comment after a semicolon' => ["true;# XXX later\n", [1 => 'XXX']];
        yield 'a comment after a pipe' => ["a |# TODO later\n", [1 => 'TODO']];
        yield 'a comment after a redirection' => ["echo a >#TODO later\n", [1 => 'TODO']];
        yield 'a repeated hash' => ["## TODO later\n", [1 => 'TODO']];
        yield 'a nested hash' => ["# # TODO later\n", [1 => 'TODO']];
        yield 'an indented comment' => ["if true; then\n\t# TODO later\nfi\n", [2 => 'TODO']];
        yield 'a comment after a closed single-quoted string' => ["echo '# x' # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a closed double-quoted string with an escaped quote' => ["echo \"a \\\" # x\" # TODO later\n", [1 => 'TODO']];
        yield 'a comment after an ANSI-C string with an escaped quote' => ["echo \$'it\\'s # x' # TODO later\n", [1 => 'TODO']];
        yield 'a comment in a command substitution' => ["a=\$(date # TODO pin the zone\n)\n", [1 => 'TODO']];
        yield 'a comment in a command substitution inside double quotes' => ["a=\"\$(date # TODO pin the zone\n)\"\n", [1 => 'TODO']];
        yield 'a comment after a parameter expansion' => ["echo \${#list} \${a#b} # TODO later\n", [1 => 'TODO']];
        yield 'a comment in a subshell' => ["(cd x # TODO later\n)\n", [1 => 'TODO']];
        yield 'a comment in an array' => ["list=(a # TODO later\n b)\n", [1 => 'TODO']];
        yield 'a comment after a here-document' => ["cat <<EOF\n# inside\nEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment on the line of a here-document operator' => ["cat <<EOF # TODO later\n# inside\nEOF\n", [1 => 'TODO']];
        yield 'a comment after a here-document with a quoted delimiter' => ["cat <<'END'\n# inside\nEND\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a here-document with a partly quoted delimiter' => ["cat <<E\"N\"D\n# inside\nEND\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a here-document with stripped tabs' => ["cat <<-EOF\n\t# inside\n\tEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after two here-documents on one line' => ["cat <<A <<B\n# a\nA\n# b\nB\n# TODO later\n", [6 => 'TODO']];
        yield 'a comment after a here-string' => ["cat <<< x # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a case pattern' => ["case \$a in\n  x) true ;; # TODO later\nesac\n", [2 => 'TODO']];
        yield 'a comment after a backslash and a line feed' => ["echo a \\\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment in backticks' => ["a=`date # TODO later`\n", [1 => 'TODO']];
        yield 'a comment after a parameter with a single quote inside double quotes' => ["echo \"\${a:-it's}\" # TODO later\n", [1 => 'TODO']];
        yield 'a comment after carriage return line ends' => ["true\r\n# TODO later\r\n", [2 => 'TODO']];
        yield 'a comment after an arithmetic left shift' => ["x=\$(( 1 << 2 ))\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after an arithmetic left shift without blanks' => ["n=\$((1<<2))\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after an arithmetic left shift in double quotes' => ["echo \"\$((1 << 2))\"\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment after an arithmetic command with a shift assignment' => ["(( x <<= 1 ))\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment in a case branch inside a quoted command substitution' => ["a=\"\$(case \$b in\n  x) echo # TODO later\n  ;;\nesac)\"\n", [2 => 'TODO']];
        yield 'a comment after a case inside a command substitution' => ["a=\$(case \$b in x) echo ;; esac) # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a here-document with an empty quoted delimiter' => ["cat <<''\n# TODO body text\n\n# FIXME real comment\n", [4 => 'FIXME']];
        yield 'a comment after a here-document whose quoted delimiter holds a quote' => ["cat <<'a\"b'\ntext\na\"b\n# TODO real comment\n", [4 => 'TODO']];
        yield 'a comment after a here-document whose double-quoted delimiter holds an escaped quote' => ["cat <<\"a\\\"b\"\ntext\na\"b\n# TODO real comment\n", [4 => 'TODO']];
        yield 'a comment in a command substitution before a pending here-document body' => ["cat <<EOF \"\$(\nprintf '%s' arg\n# TODO real comment\n)\"\n# FIXME body text\nEOF\n", [3 => 'TODO']];
        yield 'a comment after a here-document inside a command substitution' => ["a=\$(cat <<EOF\n# inside\nEOF\n)\n# TODO later\n", [5 => 'TODO']];
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
        yield 'a marker in a double-quoted string over two lines' => ["echo \"a\n# TODO later\"\n"];
        yield 'a marker in an ANSI-C string' => ["echo \$'# TODO later'\n"];
        yield 'a marker after a hash inside a word' => ["echo a#TODO\n"];
        yield 'a marker after an escaped hash' => ["echo \\# TODO later\n"];
        yield 'a marker after the argument count' => ["echo \$# TODO later\n"];
        yield 'a marker in a length expansion' => ["echo \${#TODO}\n"];
        yield 'a marker in a pattern removal' => ["echo \${a# TODO}\n"];
        yield 'a marker in a here-document' => ["cat <<EOF\n# TODO later\nEOF\n"];
        yield 'a marker in a here-document with a quoted delimiter' => ["cat <<\"EOF\"\n# TODO later\nEOF\n"];
        yield 'a marker in a here-document with stripped tabs' => ["cat <<-EOF\n\t# TODO later\n\tEOF\n"];
        yield 'a marker in a double-quoted string inside a command substitution' => ["a=\$(echo \"# TODO later\")\n"];
        yield 'a marker in a base literal of an arithmetic expansion' => ["echo \$((16#ff))\n"];
        yield 'a marker after a command substitution in the same word' => ["printf '%s\\n' \$(printf word)#TODO\n"];
        yield 'a marker after a backtick substitution in the same word' => ["echo `printf word`#TODO\n"];
        yield 'a marker after the comment start' => ["# see the TODO list\n"];
        yield 'a reasoned exception' => ["# TODO(#123): drop once the image ships bash 5\n"];
        yield 'a marker in code' => ["TODO=1\n"];
        yield 'a hashbang' => ["#!/bin/sh\n"];
    }

    #[DataProvider('scriptsWithoutAMarkerComment')]
    public function testAShellSourceWithoutAMarkerCommentHasNoMarkers(string $script): void
    {
        self::assertSame([], $this->markersOn($script));
    }

    public function testCommentsComeBackWithTheirOffsetsAndTheirHashes(): void
    {
        self::assertSame([[0, '#!/bin/sh'], [15, '# two'], [21, '# three']], new ShellComments()->commentsIn("#!/bin/sh\necho # two\n# three"));
    }

    public function testAnUnclosedQuoteOrHereDocumentEndsTheScanWithoutAComment(): void
    {
        foreach (["echo '\n# TODO", "echo \"\n# TODO", "echo \$'\n# TODO", "a=\${b\n# TODO", "cat <<EOF\n# TODO", "cat <<'EOF\n# TODO", 'echo \\'] as $script) {
            self::assertSame([], $this->markersOn($script), $script);
        }
    }

    public function testAMarkerOnALineThatWasNotAddedIsNotReported(): void
    {
        $markers = new LeftUndoneComments()->markersOn("true\n# TODO later\n", [1], new ShellComments());

        self::assertSame([], $markers);
    }

    public function testManyCommentsQuotesAndHereDocumentsAreScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => str_repeat("echo \"\$(a '#')\" \${#b} # c\ncat <<EOF\n# d\nEOF\n", $size);

        $growth = $this->fastestScanOf($script(16000)) / $this->fastestScanOf($script(2000));

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $growth);
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
