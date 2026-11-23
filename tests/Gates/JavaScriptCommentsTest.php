<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Closure;
use Intakt\Gates\JavaScriptComments;
use Intakt\Gates\JavaScriptLexer;
use Intakt\Gates\JavaScriptLiterals;
use Intakt\Gates\LeftUndoneComments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JavaScriptComments::class)]
#[CoversClass(JavaScriptLexer::class)]
#[CoversClass(JavaScriptLiterals::class)]
#[CoversClass(LeftUndoneComments::class)]
final class JavaScriptCommentsTest extends TestCase
{
    private const string LINE_SEPARATOR = "\u{2028}";
    private const string PARAGRAPH_SEPARATOR = "\u{2029}";
    // Because a linear scan grows about eightfold on eight times the input and a quadratic one about sixty-fourfold, this bound leaves room for a noisy machine.
    private const float LINEAR_GROWTH_BOUND = 24.0;

    /**
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function scriptsWithAMarkerComment(): iterable
    {
        yield 'a line comment' => ["let total = 0; // TODO show the total\n", [1 => 'TODO']];
        yield 'a block comment' => ["/* FIXME the total */\n", [1 => 'FIXME']];
        yield 'the second line of a block comment' => ["/*\n * TODO show the total\n */\n", [2 => 'TODO']];
        yield 'a documentation comment' => ["let a;\n/**\n * XXX breaks on empty orders\n */\n", [3 => 'XXX']];
        yield 'a nested line comment marker' => ["// // TODO later\n", [1 => 'TODO']];
        yield 'a lower-case marker' => ["// todo: later\n", [1 => 'todo']];
        yield 'an unclosed block comment' => ["/*\n TODO later\n", [2 => 'TODO']];
        yield 'a comment after carriage return line ends' => ["let a;\r\n\r\n// TODO later\r\n", [3 => 'TODO']];
        yield 'a comment after a string holding a comment opener' => ["const a = '/*'; // TODO later\n", [1 => 'TODO']];
        yield 'a comment after an escaped quote' => ["const a = \"\\\"//\"; // TODO later\n", [1 => 'TODO']];
        yield 'a comment after a string continued on the next line' => ["const a = 'x\\\n// TODO inside'; // FIXME later\n", [2 => 'FIXME']];
        yield 'a comment after a string continued after CR LF' => ["const a = 'x\\\r\n// TODO inside'; // FIXME later\n", [2 => 'FIXME']];
        yield 'a comment after a template literal' => ["const a = `// \${b}`; // TODO later\n", [1 => 'TODO']];
        yield 'a block comment in a template expression' => ["const a = `\${b /* TODO later */}`;\n", [1 => 'TODO']];
        yield 'a line comment in a template expression' => ["const a = `\${b // TODO later\n}`;\n", [1 => 'TODO']];
        yield 'a comment after nested templates' => ["const a = `\${`\${'`'}`}`; // TODO later\n", [1 => 'TODO']];
        yield 'a comment after an object in a template expression' => ["const a = `\${ {b: {c: 1}}.b }`; // TODO later\n", [1 => 'TODO']];
        yield 'a comment after a pattern with escaped slashes' => ["const a = /\\/\\//g; // TODO later\n", [1 => 'TODO']];
        yield 'a comment after a pattern with a slash in a class' => ["const a = /[/]+/; // TODO later\n", [1 => 'TODO']];
        yield 'a comment after a pattern holding a quote' => ["const a = /'/; // TODO later\n", [1 => 'TODO']];
        yield 'a pattern after return' => ["return /'/.test(a); // TODO later\n", [1 => 'TODO']];
        yield 'a pattern after typeof' => ["typeof /'/; // TODO later\n", [1 => 'TODO']];
        yield 'a pattern after an opening parenthesis' => ["f(/'/); // TODO later\n", [1 => 'TODO']];
        yield 'a pattern after an arrow' => ["const f = () => /'/; // TODO later\n", [1 => 'TODO']];
        yield 'a pattern as the body of an if' => ["if (a) /'/.test(b); // TODO later\n", [1 => 'TODO']];
        yield 'a pattern as the body of a while' => ["while (a) /'/.test(b); // TODO later\n", [1 => 'TODO']];
        yield 'a pattern as the body of a for' => ["for (const a of b) /'/.test(a); // TODO later\n", [1 => 'TODO']];
        yield 'a pattern as the body of a for await' => ["for await (const a of b) /'/.test(a); // TODO later\n", [1 => 'TODO']];
        yield 'a division after an identifier' => ["const a = total / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a closing parenthesis' => ["const a = (total) / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a closing bracket' => ["const a = totals[0] / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a closing brace' => ["const a = {}\n/ 2 + '/'; // TODO later\n", [2 => 'TODO']];
        yield 'a division after a number' => ["const a = 4 / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a trailing decimal point' => ["const a = 4. / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a leading decimal point' => ["const a = .5 / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after an exponent' => ["const a = 1.5e-3 / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a hexadecimal number' => ["const a = 0xFF / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a big integer' => ["const a = 1_000n / 2n + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a string' => ["const a = '4' / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a template literal' => ["const a = `4` / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a pattern' => ["const a = /4/ / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after an increment' => ["const a = b++ / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a property named like a keyword' => ["const a = order.return / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after an identifier with a multibyte letter' => ["const a = größe / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after an identifier with a multibyte letter sharing the separator lead byte' => ["const a = x\u{2160} / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a spread argument' => ["f(...a / 2 + '/'); // TODO later\n", [1 => 'TODO']];
        yield 'code after a line comment ended by a line separator' => ['// note'.self::LINE_SEPARATOR."let a = 1; /* TODO later */\n", [1 => 'TODO']];
        yield 'code after a line comment ended by a paragraph separator' => ['// note'.self::PARAGRAPH_SEPARATOR."let a = 1; /* TODO later */\n", [1 => 'TODO']];
        yield 'a starred block comment line after a line separator' => ['/* note'.self::LINE_SEPARATOR." * TODO later */\n", [1 => 'TODO']];
        yield 'a division after a line separator' => ['let a = b'.self::LINE_SEPARATOR."/ 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a variable named of' => ["const of = 4; const a = of / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a pattern after of in a for head' => ["for (const a of /'/.exec(b)) f(a); // TODO later\n", [1 => 'TODO']];
        yield 'a division after a private property named like a keyword' => ["class C { #return = 4; f() { return this.#return / 2 + '/'; /* TODO later */ } }\n", [1 => 'TODO']];
        yield 'a division after a nested object' => ["const a = {b: {}} / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after an object returned by an arrow' => ["const f = () => ({}) / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after an identifier with multibyte letters sharing space lead bytes' => ["const a = \u{00B5}\u{3042}\u{1E00}\u{FB01} / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a variable named of in an if head' => ["if (of / 2) f('/'); // TODO later\n", [1 => 'TODO']];
        yield 'a division after a variable named of in a while head' => ["while (of / 2) f('/'); // TODO later\n", [1 => 'TODO']];
        yield 'a division after a variable named of in the condition of a classic for' => ["for (; of / 2; ) f('/'); // TODO later\n", [1 => 'TODO']];
        yield 'a division after a variable named of declared in a for of head' => ["for (const of of xs) f(of / 2 + '/'); // TODO later\n", [1 => 'TODO']];
        yield 'a pattern after of in a for head that declares a variable named of' => ["for (const of of /'/.exec(b)) f(of); // TODO later\n", [1 => 'TODO']];
        yield 'a pattern after of in a for await head' => ["for await (const a of /'/.exec(b)) f(a); // TODO later\n", [1 => 'TODO']];
        yield 'a division after a function expression' => ["const n = function () {} / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a class expression' => ["const n = class {} / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a class expression with a heritage' => ["const n = class extends f({}) {} / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after a function expression with an object as a default' => ["const n = function (a = {}) {} / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after an object in a ternary branch inside a block' => ["{ const a = b ? c : {} / 2 + '/'; /* TODO later */ }\n", [1 => 'TODO']];
        yield 'a division after an object after optional chaining in a ternary' => ["{ const a = b?.c ? d : {} / 2 + '/'; /* TODO later */ }\n", [1 => 'TODO']];
        yield 'a division after an identifier with a braced Unicode escape' => ["const n = \\u{0061} / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division after an identifier ending in a braced Unicode escape' => ["const n = a\\u{62} / 2 + '/'; // TODO later\n", [1 => 'TODO']];
        yield 'a division in an object exported as default' => ["export default {a: 1 / 2 + '/'}; // TODO real\n", [1 => 'TODO']];
        yield 'a division after an object beside a key named class' => ["const o = {class: 1, b: {c: {} / 2 + '/'}}; // TODO later\n", [1 => 'TODO']];
        yield 'a division after an object beside a key named function' => ["const o = {function : 1, b: {c: {} / 2 + '/'}}; // TODO later\n", [1 => 'TODO']];
        yield 'a division after an object beside a key named class with a long run of spaces before its colon' => ['const o = {class'.str_repeat(' ', 20000).": 1, b: {c: {} / 2 + '/'}}; // TODO real\n", [1 => 'TODO']];
        yield 'a comment on the line after a hashbang' => ["#!/usr/bin/env node\n// TODO real\nfunction f() {}\n", [2 => 'TODO']];
        yield 'a comment on the line after a hashbang holding a quote' => ["#!/usr/bin/env -S node --title='a\n// TODO real\n", [2 => 'TODO']];
        yield 'a comment on the line after a hashbang ended by a line separator' => ['#!/usr/bin/env node'.self::LINE_SEPARATOR."// TODO real\n", [1 => 'TODO']];
        yield 'a comment after a hashbang that does not start the source' => ["\n#!/usr/bin/env node // TODO real\n", [2 => 'TODO']];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lineEnds(): iterable
    {
        yield 'a line feed' => ["\n"];
        yield 'a carriage return' => ["\r"];
        yield 'a carriage return and line feed' => ["\r\n"];
        yield 'a line separator' => [self::LINE_SEPARATOR];
        yield 'a paragraph separator' => [self::PARAGRAPH_SEPARATOR];
    }

    #[DataProvider('lineEnds')]
    public function testAnUnclosedPatternEndingInABackslashEndsAtTheLineEnd(string $lineEnd): void
    {
        $prefix = 'const pattern = /unclosed\\'.$lineEnd;

        self::assertSame([[strlen($prefix), '// TODO later']], new JavaScriptComments()->commentsIn($prefix.'// TODO later'));
    }

    #[DataProvider('lineEnds')]
    public function testAnUnclosedClassOfAPatternEndingInABackslashEndsAtTheLineEnd(string $lineEnd): void
    {
        $prefix = 'const pattern = /[unclosed\\'.$lineEnd;

        self::assertSame([[strlen($prefix), '// TODO later']], new JavaScriptComments()->commentsIn($prefix.'// TODO later'));
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
        yield 'a line comment in a single-quoted string' => ["const a = '// TODO later';\n"];
        yield 'a block comment in a double-quoted string' => ["const a = \"/* TODO later */\";\n"];
        yield 'a line comment in a template literal' => ["const a = `\n// TODO later\n`;\n"];
        yield 'a line comment in template text after an expression' => ["const a = `\${'`'} // TODO later`;\n"];
        yield 'a line comment in a template nested in an expression' => ["const a = `\${`// TODO later`}`;\n"];
        yield 'a line comment in a string after a line separator' => ["const a = '".self::LINE_SEPARATOR."// TODO later';\n"];
        yield 'a quote in a pattern before a string holding a comment' => ["const a = /'/.test(b) && '// TODO later';\n"];
        yield 'a quote in a class of a pattern before a string holding a comment' => ["const a = /[/']/.test(b) && '// TODO later';\n"];
        yield 'a quote in a pattern as the body of a for await' => ["for await (const line of lines) /'/.test(line) && log('// TODO later');\n"];
        yield 'a quote after a division before a string holding a comment' => ["const a = 4. / 2 + \"'\" + '// TODO later';\n"];
        yield 'a comment opener in a class of a pattern after export default' => ["export default /[// TODO later]/;\n"];
        yield 'a comment opener in a class of a pattern after an if block' => ["if (ready) {} /[// TODO later]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after an else' => ["if (ready) {} else /[// TODO later]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after a function declaration' => ["function f() {}\n/[// TODO later]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after an arrow' => ["const f = () => /[// TODO later]/;\n"];
        yield 'a comment opener in a class of a pattern after a nested block' => ["{ {} /[// TODO later]/.test(text); }\n"];
        yield 'a comment opener in a class of a pattern after a no-break space' => ["const pattern =\u{00A0}/[// TODO later]/;\n"];
        yield 'a comment opener in a class of a pattern after return and an ideographic space' => ["return\u{3000}/[// TODO later]/;\n"];
        yield 'a comment opener in a class of a pattern after a byte order mark' => ["\u{FEFF}/[// TODO later]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after a block in a case' => ["switch (n) { case 1: {} /[// TODO later]/.test(s); }\n"];
        yield 'a comment opener in a class of a pattern after a block in a default case' => ["switch (n) { default: {} /[// TODO later]/.test(s); }\n"];
        yield 'a comment opener in a class of a pattern after a labeled block' => ["outer: {} /[// TODO later]/.test(s);\n"];
        yield 'a comment opener in a class of a pattern after an async function declaration' => ["async function f() {}\n/[// TODO later]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after an exported function declaration' => ["export function f() {}\n/[// TODO later]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after a class declaration' => ["class C { f() {} }\n/[// TODO later]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after a block in a function expression' => ["const f = function () { {} /[// TODO later]/.test(s); };\n"];
        yield 'a comment opener in a class of a pattern after a default exported function declaration' => ["export default function f() {}\n/[// TODO inside]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after a default exported anonymous function declaration' => ["export default function () {}\n/[// TODO inside]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after a default exported async function declaration' => ["export default async function f() {}\n/[// TODO inside]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after a default exported class declaration' => ["export default class C {}\n/[// TODO inside]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after a default exported class declaration with a heritage' => ["export default class extends f({}) {}\n/[// TODO inside]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after an export list' => ["export { a }\n/[// TODO inside]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern returned from a default case' => ["switch (n) { default: return /[// TODO inside]/.test(s); }\n"];
        yield 'a comment opener in a class of a pattern after an async function declaration with a no-break space' => ["async\u{00A0}function f() {}\n/[// TODO inside]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after an async function declaration with an ideographic space' => ["async\u{3000}function f() {}\n/[// TODO inside]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after an async function declaration with a long run of spaces' => ['async'.str_repeat(' ', 20000)."function f() {}\n/[// TODO inside]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after a hashbang and a function declaration' => ["#!/usr/bin/env node\nfunction f() {}\n/[// TODO inside]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after a hashbang and an async function declaration' => ["#!/usr/bin/env node\nasync function f() {}\n/[// TODO inside]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after a hashbang and a class declaration' => ["#!/usr/bin/env node\nclass C {}\n/[// TODO inside]/.test(text);\n"];
        yield 'a comment opener in a class of a pattern after a hashbang ended by a carriage return' => ["#!/usr/bin/env node\rfunction f() {}\r/[// TODO inside]/.test(text);\r"];
        yield 'a comment opener in a class of a pattern after a byte order mark, a hashbang and a function declaration' => ["\u{FEFF}#!/usr/bin/env node\nfunction f() {}\n/[// TODO inside]/.test(text);\n"];
        yield 'a marker in a hashbang, which is no JavaScript comment' => ["#!/usr/bin/env node // TODO later\n"];
        yield 'a marker after the comment start' => ["// see the TODO list\n"];
        yield 'a reasoned exception' => ["// TODO(#123): show the total once the forge sends it\n"];
        yield 'a marker behind a hash, which is no JavaScript comment' => ["/* # TODO is shell syntax */\n"];
        yield 'a marker in code' => ["const TODO = 1;\n"];
        yield 'an empty block comment' => ["/**/\n"];
    }

    #[DataProvider('scriptsWithoutAMarkerComment')]
    public function testAJavaScriptSourceWithoutAMarkerCommentHasNoMarkers(string $script): void
    {
        self::assertSame([], $this->markersOn($script));
    }

    public function testABlockCommentSplitAtLineSeparatorsIsReportedPartByPartFromItsOwnOffsets(): void
    {
        $script = '/* a'.self::LINE_SEPARATOR.'b'.self::PARAGRAPH_SEPARATOR.'c */';

        self::assertSame([[0, '/* a'], [7, 'b'], [11, 'c */']], new JavaScriptComments()->commentsIn($script));
    }

    public function testAMarkerOnALineThatWasNotAddedIsNotReported(): void
    {
        $markers = new LeftUndoneComments()->markersOn("let a;\n// TODO later\n", [1], new JavaScriptComments());

        self::assertSame([], $markers);
    }

    public function testAnUnclosedStringTemplateOrPatternEndsTheScanWithoutAComment(): void
    {
        foreach (["'// TODO", '`// TODO', '`${"// TODO', 'a = /[// TODO', 'a = 1\\'] as $script) {
            self::assertSame([], $this->markersOn($script), $script);
        }
    }

    public function testABlockCommentSplitByManySeparatorsIsScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => '/* a'.str_repeat(self::LINE_SEPARATOR.' * b', $size)." TODO */\n";

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $this->growthOf($script));
    }

    public function testDeeplyNestedTemplateExpressionsAndBracketsAreScannedInLinearTime(): void
    {
        $script = static fn (int $size): string => str_repeat('`${[({', $size).'/* TODO */'.str_repeat('})]}`', $size)."\n";

        self::assertLessThan(self::LINEAR_GROWTH_BOUND, $this->growthOf($script));
        self::assertSame([1 => 'TODO'], $this->markersOn($script(1000)));
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
        return new LeftUndoneComments()->markersOn($script, range(1, substr_count($script, "\n") + 1), new JavaScriptComments());
    }
}
