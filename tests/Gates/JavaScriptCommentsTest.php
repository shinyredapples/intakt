<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\JavaScriptBracket;
use Intakt\Gates\JavaScriptCode;
use Intakt\Gates\JavaScriptComments;
use Intakt\Gates\JavaScriptContext;
use Intakt\Gates\JavaScriptFrame;
use Intakt\Gates\JavaScriptOpening;
use Intakt\Gates\JavaScriptPosition;
use Intakt\Gates\LeftUndoneComments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JavaScriptComments::class)]
#[CoversClass(LeftUndoneComments::class)]
#[CoversClass(JavaScriptPosition::class)]
#[CoversClass(JavaScriptContext::class)]
#[CoversClass(JavaScriptCode::class)]
#[CoversClass(JavaScriptBracket::class)]
#[CoversClass(JavaScriptFrame::class)]
#[CoversClass(JavaScriptOpening::class)]
final class JavaScriptCommentsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function scriptsWithAMarkerComment(): iterable
    {
        yield 'a line comment' => ["const total = 1;\n// TODO: show the total\n", [2 => 'TODO']];
        yield 'a line comment without a space' => ["//FIXME the total\n", [1 => 'FIXME']];
        yield 'a line comment behind code' => ["const total = 1; // XXX off by one\n", [1 => 'XXX']];
        yield 'a block comment' => ["/* TODO show the total */\n", [1 => 'TODO']];
        yield 'the second line of a block comment' => ["/*\n  FIXME the total\n*/\n", [2 => 'FIXME']];
        yield 'a starred line of a doc comment' => ["/**\n * The order.\n * XXX breaks on empty orders\n */\n", [3 => 'XXX']];
        yield 'a one-line doc comment' => ["/** TODO describe the order */\n", [1 => 'TODO']];
        yield 'a marker behind nested comment syntax' => ["// // TODO later\n", [1 => 'TODO']];
        yield 'a comment in a template expression' => ["const a = `total \${ /* TODO round */ total }`;\n", [1 => 'TODO']];
        yield 'a comment in a nested template expression' => ["const a = `a \${ `b \${ x /* FIXME */ } c` } d`;\n", [1 => 'FIXME']];
        yield 'a comment after a template with a brace in a string' => ["const a = `\${ '}' }`; // TODO later\n", [1 => 'TODO']];
        yield 'a comment after a regular expression' => ["const re = /a'b/; // TODO later\n", [1 => 'TODO']];
        yield 'a comment after a regular expression with a slash in a class' => ["const re = /[/]'/; // TODO later\n", [1 => 'TODO']];
        yield 'a comment after a regular expression with an escaped slash' => ["const re = /a\\/'/; // TODO later\n", [1 => 'TODO']];
        yield 'a comment after a regular expression behind return' => ["function f() { return /'/; } // TODO later\n", [1 => 'TODO']];
        yield 'a comment after a division' => ["const half = total / 2; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of a call' => ["const half = f(a) / g(b); // TODO round\n", [1 => 'TODO']];
        yield 'a comment after two divisions' => ["const q = a / b / c; /* FIXME */\n", [1 => 'FIXME']];
        yield 'a comment after an escaped quote' => ["const a = 'it\\'s'; // TODO later\n", [1 => 'TODO']];
        yield 'a comment after a string holding a comment end' => ["const a = '*/'; /* TODO later */\n", [1 => 'TODO']];
        yield 'a comment after carriage return line ends' => ["const a = 1;\r\n\r\n// TODO later\r\n", [3 => 'TODO']];
        yield 'an unclosed block comment' => ["const a = 1;\n/* TODO later\n", [2 => 'TODO']];
        yield 'a comment after a string left open on its line' => ["const a = 'open\n// TODO later\n", [2 => 'TODO']];
        yield 'a comment after a regular expression left open on its line' => ["const re = /[a'\n// TODO later\n", [2 => 'TODO']];
        yield 'a lower-case marker' => ["// todo: later\n", [1 => 'todo']];
        yield 'a comment after a division of a postfix increment' => ["const n = value++ / 2; /* TODO round */\n", [1 => 'TODO']];
        yield 'a comment after a division of a postfix decrement' => ["const n = value-- / 2; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of an object literal' => ["const n = {} / 2; /* TODO round */\n", [1 => 'TODO']];
        yield 'a comment after a division of an object literal behind return' => ["function f() { return {} / 2; } // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of a property named like a keyword' => ["const n = obj.return / 2; /* TODO round */\n", [1 => 'TODO']];
        yield 'a comment after a division of a method named like a condition keyword' => ["const n = obj.if(a) / 2; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of a Unicode identifier' => ["const n = π / 2; /* TODO round */\n", [1 => 'TODO']];
        yield 'a comment after a regular expression behind a block' => ["function f() {}\n/'/.test(a); // TODO later\n", [2 => 'TODO']];
        yield 'a comment after a line comment ended by a line separator' => ["// ordinary\u{2028}/* TODO finish */\n", [1 => 'TODO']];
        yield 'a comment after a line comment ended by a paragraph separator' => ["// ordinary\u{2029}/* TODO finish */\n", [1 => 'TODO']];
        yield 'a block comment line after a line separator' => ["/* ordinary\u{2028}TODO finish */\n", [1 => 'TODO']];
        yield 'a block comment line after a paragraph separator' => ["/* ordinary\u{2029} * FIXME finish */\n", [1 => 'FIXME']];
        yield 'a comment after a division of a function expression' => ["const n = function () {} / 2; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of a named generator expression' => ["const n = function* g(a) { yield a; } / 2; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of a class expression' => ["const n = class {} / 2; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of a class expression with a heritage call' => ["const n = class A extends f(b) {} / 2; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of a property behind a spaced dot' => ["const n = obj. return / 2; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of a property behind a comment' => ["const n = obj./* c */return / 2; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of a property behind optional chaining' => ["const n = obj?.return / 2; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of an identifier named of' => ["const of = 4; const n = of / 2; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of an object literal in a ternary' => ["const n = ok ? {} : {} / 2; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a division of an object literal as a property value' => ["const o = { a: {} / 2 }; // TODO round\n", [1 => 'TODO']];
        yield 'a comment after a regular expression behind a for-of head' => ["for (const x of /'/.exec(a)) {} // TODO later\n", [1 => 'TODO']];
    }

    /**
     * @param array<int, string> $markers
     */
    #[DataProvider('scriptsWithAMarkerComment')]
    public function testAMarkerInAJavaScriptCommentIsReportedOnItsGitLine(string $script, array $markers): void
    {
        self::assertSame($markers, $this->markersOn($script));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scriptsWithoutAMarkerComment(): iterable
    {
        yield 'a marker in a single-quoted string' => ["const a = 'TODO: show the total';\n"];
        yield 'a comment in a single-quoted string' => ["const a = '// TODO later';\n"];
        yield 'a comment in a double-quoted string' => ["const a = \"/* FIXME */\";\n"];
        yield 'a comment in a template literal' => ["const a = `// TODO later`;\n"];
        yield 'a comment on the second line of a template literal' => ["const a = `first\n// TODO later\n`;\n"];
        yield 'a comment in a string in a template expression' => ["const a = `\${ '// TODO later' }`;\n"];
        yield 'a comment behind an escaped quote' => ["const a = 'it\\'s // TODO later';\n"];
        yield 'a comment behind an escaped backtick' => ["const a = `a \\` // TODO later`;\n"];
        yield 'a comment in a regular expression' => ["const re = /\\/\\/ TODO/;\n"];
        yield 'a comment opener in a regular expression' => ["const re = /a[/*]b TODO/;\n"];
        yield 'a regular expression with a class holding a slash' => ["const re = /[/]/g; const b = '// TODO later';\n"];
        yield 'a regular expression after an opening parenthesis' => ["test(/[/* TODO */]/);\n"];
        yield 'a marker after the comment start' => ["// see the TODO list\n"];
        yield 'a reasoned exception' => ["// TODO(#123): show the total once the forge sends it\n"];
        yield 'a reasoned exception in a block comment' => ["/* FIXME(#123): round once the forge sends cents */\n"];
        yield 'a marker in plain identifiers' => ["const TODO = 1;\n"];
        yield 'a regular expression after an if condition' => ["if (ok) /[/* TODO */]/.test(input);\n"];
        yield 'a regular expression after a while condition' => ["while (ok) /[/* TODO */]/.exec(input);\n"];
        yield 'a regular expression after a block' => ["{}\n/[/* TODO */]/.test(input);\n"];
        yield 'a comment in a string continued over a carriage return line end' => ["const s = 'one\\\r\n// TODO in string';\n"];
        yield 'a regular expression after a labeled block' => ["label: {} /[/* TODO */]/.test(input);\n"];
        yield 'a regular expression after a case clause block' => ["switch (a) { case 1: {} /[/* TODO */]/.test(input); }\n"];
        yield 'a regular expression after a condition behind a no-break space' => ["if\u{00a0}(ok) /[/* TODO */]/.test(input);\n"];
        yield 'a regular expression after a condition behind an ideographic space' => ["if\u{3000}(ok) /[/* TODO */]/.test(input);\n"];
        yield 'a regular expression after a condition behind a comment' => ["if /* c */ (ok) /[/* TODO */]/.test(input);\n"];
        yield 'a regular expression after a function declaration' => ["function f() {} /[/* TODO */]/.test(input);\n"];
        yield 'a regular expression after an async function declaration' => ["async function f() {} /[/* TODO */]/.test(input);\n"];
        yield 'a regular expression after a class declaration' => ["class A extends B {} /[/* TODO */]/.test(input);\n"];
        yield 'a regular expression after an arrow function body' => ["const f = () => {}\n/[/* TODO */]/.test(input);\n"];
        yield 'a regular expression after a catch block' => ["try {} catch (e) {} /[/* TODO */]/.test(input);\n"];
    }

    #[DataProvider('scriptsWithoutAMarkerComment')]
    public function testAJavaScriptSourceWithoutAMarkerCommentHasNoMarkers(string $script): void
    {
        self::assertSame([], $this->markersOn($script));
    }

    public function testAMarkerOnALineThatWasNotAddedIsNotReported(): void
    {
        $markers = new LeftUndoneComments()->markersOn("const a = 1;\n// TODO later\n", [1], new JavaScriptComments());

        self::assertSame([], $markers);
    }

    public function testACommentIsGivenWithItsDelimiterAndTheOffsetWhereItStarts(): void
    {
        $comments = new JavaScriptComments()->commentsIn("a = 1; // TODO\n/* b */\n");

        self::assertSame([[7, '// TODO'], [15, '/* b */']], $comments);
    }

    public function testAnUnclosedTemplateLiteralReadsToTheEndOfTheFile(): void
    {
        self::assertSame([], new JavaScriptComments()->commentsIn("const a = `open \${ x\n"));
    }

    public function testATemplateExpressionNestedThousandsOfTimesIsReadInUnderASecond(): void
    {
        $script = str_repeat('`${', 2000).'/* TODO */'.str_repeat('}`', 2000)."\n";
        $start = hrtime(true);

        $comments = new JavaScriptComments()->commentsIn($script);

        self::assertSame([[6000, '/* TODO */']], $comments);
        self::assertLessThan(1_000_000_000, hrtime(true) - $start);
    }

    public function testBracketsNestedTensOfThousandsOfTimesAreReadInUnderASecond(): void
    {
        $script = str_repeat('({[', 30_000).'1'.str_repeat(']})', 30_000).'; // TODO round';
        $start = hrtime(true);

        $comments = new JavaScriptComments()->commentsIn($script);

        self::assertSame([[180_003, '// TODO round']], $comments);
        self::assertLessThan(1_000_000_000, hrtime(true) - $start);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function literalsEndingInABackslashAtTheEndOfTheFile(): iterable
    {
        yield 'a single-quoted string' => ["'open\\"];
        yield 'a double-quoted string' => ['"open\\'];
        yield 'a template literal' => ['`open\\'];
        yield 'a regular expression' => ['x = /open\\'];
        yield 'a regular expression class' => ['x = /[open\\'];
    }

    #[DataProvider('literalsEndingInABackslashAtTheEndOfTheFile')]
    public function testALiteralEndingInABackslashAtTheEndOfTheFileHasNoComments(string $script): void
    {
        self::assertSame([], new JavaScriptComments()->commentsIn($script));
    }

    /**
     * @return array<int, string>
     */
    private function markersOn(string $script): array
    {
        return new LeftUndoneComments()->markersOn($script, range(1, substr_count($script, "\n") + 1), new JavaScriptComments());
    }
}
