<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Closure;
use Intakt\Gates\LeftUndoneComments;
use Intakt\Gates\ShellComments;
use Intakt\Gates\ShellHereDocument;
use Intakt\Gates\ShellHereDocuments;
use Intakt\Gates\ShellLexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShellLexer::class)]
#[CoversClass(ShellHereDocuments::class)]
#[CoversClass(ShellHereDocument::class)]
#[UsesClass(ShellComments::class)]
#[UsesClass(LeftUndoneComments::class)]
final class ShellHereDocumentsTest extends TestCase
{
    // Because a linear scan grows about eightfold on eight times the input and a quadratic one about sixty-fourfold, this bound leaves room for a noisy machine.
    private const float LINEAR_GROWTH_BOUND = 24.0;

    /**
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function scriptsWithAMarkerComment(): iterable
    {
        yield 'a comment after a here-document' => ["cat <<EOF\n# TODO in the body\nEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment on the line of the redirection' => ["cat <<EOF # TODO on the line\n# TODO in the body\nEOF\n", [1 => 'TODO']];
        yield 'a comment after a backslash-newline before the delimiter of an unquoted here-document' => ["cat <<EOF\nprefix\\\nEOF\n# TODO in the body\nEOF\n# TODO later\n", [6 => 'TODO']];
        yield 'a comment after an escaped backslash before the delimiter of an unquoted here-document' => ["cat <<EOF\nprefix\\\\\nEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a delimiter split by a backslash-newline in the body' => ["cat <<EOF\nEO\\\nF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a backslash before the delimiter of a single-quoted here-document' => ["cat <<'EOF'\nprefix\\\nEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a double-quoted delimiter' => ["cat <<\"EOF\"\n# TODO in the body\nEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a backslash-quoted delimiter' => ["cat <<\\EOF\n# TODO in the body\nEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after an ANSI-C quoted delimiter' => ["cat <<\$'EOF'\n# TODO in the body\nEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a delimiter with a space before it' => ["cat << EOF\n# TODO in the body\nEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a tab-stripped here-document' => ["cat <<-EOF\n\t# TODO in the body\n\tEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after two here-documents on one line' => ["cat <<A - <<B\n# TODO in a\nA\n# TODO in b\nB\n# TODO later\n", [6 => 'TODO']];
        yield 'a comment after a here-document piped into a subshell' => ["cat <<EOF | (cat)\n# TODO in the body\nEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a here-document before a subshell in a list' => ["cat <<EOF && (\n# TODO in the body\nEOF\n  cat\n)\n# TODO later\n", [6 => 'TODO']];
        yield 'a comment after a here-document piped into a case' => ["cat <<EOF | case \$1 in\n# TODO in the body\nEOF\n  start) cat ;;\nesac\n# TODO later\n", [6 => 'TODO']];
        yield 'a comment after a here-document piped into a group' => ["cat <<EOF | {\n# TODO in the body\nEOF\n  cat\n}\n# TODO later\n", [6 => 'TODO']];
        yield 'a comment after a here-document opened in a substitution and read after the line' => ["echo \"\$(cat <<EOF)\"\n# TODO in the body\nEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a here-document read inside a substitution' => ["x=\$(cat <<EOF\n# TODO in the body\nEOF\n)\n# TODO later\n", [5 => 'TODO']];
        yield 'a comment inside a substitution' => ["x=\$(\n  # TODO inside\n  date\n)\n", [2 => 'TODO']];
        yield 'a comment inside a substitution in double quotes' => ["echo \"\$(date # TODO inside\n)\"\n", [1 => 'TODO']];
        yield 'a comment after quotes inside a substitution in double quotes' => ["echo \"\$(printf '%s\"' x)\"\n# TODO later\n", [2 => 'TODO']];
        yield 'a comment in a case inside a substitution in double quotes' => ["x=\"\$(case \$1 in\n  start) # TODO inside\n    echo a ;;\nesac)\"\n", [2 => 'TODO']];
        yield 'a comment after a subshell inside a substitution in double quotes' => ["x=\"\$( (cd /; pwd) # TODO inside\n)\"\n", [1 => 'TODO']];
        yield 'a comment after the word case as an argument in a substitution' => ["echo \"\$(echo case)\" '\"' # TODO later\n", [1 => 'TODO']];
        yield 'a comment after a here-string' => ["cat <<<EOF\n# TODO later\nEOF\n", [2 => 'TODO']];
        yield 'a comment after a shift in an arithmetic expansion' => ["echo \$((1 << 2))\n# TODO later\n2))\n", [2 => 'TODO']];
        yield 'a comment after a shift in a nested arithmetic group' => ["echo \$(( (1<<2) ))\n# TODO later\n2)\n", [2 => 'TODO']];
        yield 'a comment after a shift in an arithmetic command' => ["((x = 1<<2))\n# TODO later\n2))\n", [2 => 'TODO']];
        yield 'a comment after a here-document marker in quotes' => ["echo '<<EOF' \"<<EOF\"\n# TODO later\nEOF\n", [2 => 'TODO']];
        yield 'a comment in a branch after a nested case behind a parenthesized pattern' => ["x=\"\$(case \"\$1\" in\n  (start) case \"\$2\" in\n    fast) echo fast ;;\n  esac ;;\n  stop) # TODO branch\n    echo stop ;;\nesac)\"\n# TODO later\n", [5 => 'TODO', 8 => 'TODO']];
        yield 'a comment after a case with parenthesized patterns and fall-through terminators' => ["case \$1 in\n  (a) echo a ;&\n  (b) echo b ;;&\n  # TODO between branches\n  (c) (echo c) ;;\nesac\n# TODO later\n", [4 => 'TODO', 7 => 'TODO']];
        yield 'a comment after a here-document read inside a nested case branch' => ["case \$1 in\n  (a) cat <<EOF ;;\n# TODO in the body\nEOF\nesac\n# TODO later\n", [6 => 'TODO']];
        yield 'a comment in a branch after a nested case behind a parenthesized pattern on a continued case line' => ["x=\"\$(case \"\$1\" in \\\n  (start) case \"\$2\" in\n    fast) echo fast ;;\n  esac ;;\n  stop) # TODO branch\n    echo stop ;;\nesac)\"\n# TODO later\n", [5 => 'TODO', 8 => 'TODO']];
        yield 'a comment in a branch after a nested case behind a parenthesized pattern on a continued branch terminator' => ["x=\"\$(case \"\$1\" in\n  a) echo a ;; \\\n  (start) case \"\$2\" in\n    fast) echo fast ;;\n  esac ;;\n  stop) # TODO branch\n    echo stop ;;\nesac)\"\n# TODO later\n", [6 => 'TODO', 9 => 'TODO']];
        yield 'a comment in a branch after a nested case behind a parenthesized pattern on a continued fall-through terminator' => ["x=\"\$(case \"\$1\" in\n  a) echo a ;& \\\n  (start) case \"\$2\" in\n    fast) echo fast ;;\n  esac ;;\n  stop) # TODO branch\n    echo stop ;;\nesac)\"\n# TODO later\n", [6 => 'TODO', 9 => 'TODO']];
        yield 'a comment in a branch after a here-document piped into a continued case line with a nested case' => ["x=\"\$(cat <<EOF | case \"\$1\" in \\\n  (start) case \"\$2\" in\n# TODO body\nEOF\n    fast) echo fast ;;\n  esac ;;\n  stop) # TODO branch\n    echo stop ;;\nesac)\"\n# TODO later\n", [7 => 'TODO', 10 => 'TODO']];
        yield 'a comment after a case whose pattern starts with an escaped parenthesis' => ["x=\"\$(case \"\$1\" in\n  \\(a) echo a ;;\nesac)\"\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a for loop over in inside a case branch' => ["case \$1 in\n  a) for x in (y); do :; done ;;\nesac\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a single-quoted delimiter with a backslash' => ["cat <<'END\\MARK'\n# TODO in the body\nEND\\MARK\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after an ANSI-C quoted delimiter with an escape' => ["cat <<\$'E\\x4fF'\n# TODO in the body\nEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a double-quoted delimiter with an escaped quote' => ["cat <<\"E\\\"F\"\n# TODO in the body\nE\"F\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after a delimiter of mixed quoting' => ["cat <<E'O'\"F\"\n# TODO in the body\nEOF\n# TODO later\n", [4 => 'TODO']];
        yield 'a comment after outer and inner here-documents on one line' => ["cat <<OUT \"\$(cat <<IN)\"\n# TODO outer body\nOUT\n# TODO inner body\nIN\n# TODO later\n", [6 => 'TODO']];
        yield 'a comment inside a substitution in a parameter default after its here-document' => ["echo \"\${value:-\$(cat <<EOF\n# TODO in the body\nEOF\n# TODO inside\n)}\"\n# TODO later\n", [4 => 'TODO', 6 => 'TODO']];
    }

    /**
     * @param array<int, string> $markers
     */
    #[DataProvider('scriptsWithAMarkerComment')]
    public function testAMarkerInAShellCommentAroundHereDocumentsAndSubstitutionsIsReported(string $script, array $markers): void
    {
        self::assertSame($markers, $this->markersOn($script));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scriptsWithoutAMarkerComment(): iterable
    {
        yield 'a marker in a here-document body' => ["cat <<EOF\n# TODO in the body\nEOF\n"];
        yield 'a marker in an unclosed here-document body' => ["cat <<EOF\n# TODO in the body\n"];
        yield 'a marker after an indented delimiter of a plain here-document' => ["cat <<EOF\n\tEOF\n# TODO in the body\nEOF\n"];
        yield 'a hash inside a word of a substitution' => ["echo \$(echo a#TODO)\n"];
        yield 'a hash right after a substitution' => ["echo \$(date)#TODO\n"];
        yield 'a hash right after a substitution in double quotes' => ["echo \"\$(date)\"#TODO\n"];
    }

    #[DataProvider('scriptsWithoutAMarkerComment')]
    public function testAMarkerOutsideAShellCommentAroundHereDocumentsAndSubstitutionsIsNotReported(string $script): void
    {
        self::assertSame([], $this->markersOn($script));
    }

    public function testManyHereDocumentSubstitutionsOnOneLineAreScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => 'echo '.str_repeat('"$(cat <<EOF)"', $size)."\n".str_repeat("# TODO in the body\nEOF\n", $size)."# TODO later\n";

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $this->growthOf($script));
        self::assertSame([2002 => 'TODO'], $this->markersOn($script(1000)));
    }

    public function testDeeplyNestedHereDocumentSubstitutionsAreScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => str_repeat('$(cat <<EOF ', $size).'x'.str_repeat(')', $size)."\n".str_repeat("EOF\n", $size)."# TODO later\n";

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $this->growthOf($script));
        self::assertSame([1002 => 'TODO'], $this->markersOn($script(1000)));
    }

    public function testALongBackslashContinuedHereDocumentBodyIsScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => "cat <<EOF\n".str_repeat("# TODO in the body \\\n", $size)."\nEOF\n# TODO later\n";

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
