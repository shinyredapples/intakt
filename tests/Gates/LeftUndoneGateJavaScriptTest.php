<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\AddedLines;
use Intakt\Gates\CommentLanguages;
use Intakt\Gates\JavaScriptComments;
use Intakt\Gates\JavaScriptLexer;
use Intakt\Gates\JavaScriptLiterals;
use Intakt\Gates\LeftUndoneCode;
use Intakt\Gates\LeftUndoneComments;
use Intakt\Gates\LeftUndoneGate;
use Intakt\Gates\LeftUndoneTokens;
use Intakt\Gates\PhpComments;
use Intakt\Gates\Scope;
use Intakt\Gates\TwigComments;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(LeftUndoneGate::class)]
#[CoversClass(CommentLanguages::class)]
#[CoversClass(JavaScriptComments::class)]
#[CoversClass(LeftUndoneComments::class)]
#[UsesClass(JavaScriptLexer::class)]
#[UsesClass(JavaScriptLiterals::class)]
#[UsesClass(PhpComments::class)]
#[UsesClass(TwigComments::class)]
#[UsesClass(AddedLines::class)]
#[UsesClass(LeftUndoneCode::class)]
#[UsesClass(LeftUndoneTokens::class)]
#[UsesClass(Workspace::class)]
#[UsesClass(Scope::class)]
#[UsesClass(Sandbox::class)]
final class LeftUndoneGateJavaScriptTest extends SandboxTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addedJavaScriptMarkerComments(): iterable
    {
        yield 'a TODO line comment' => ['// TODO: show the total', 'TODO'];
        yield 'a FIXME block comment' => ['/* FIXME the total is off by one */', 'FIXME'];
        yield 'an XXX doc comment' => ['/** XXX breaks on empty orders */', 'XXX'];
        yield 'a trailing comment after code' => ['const total = 0; // todo round it', 'todo'];
        yield 'a line comment spaced by a no-break space' => ["//\u{00A0}TODO show the total", 'TODO'];
        yield 'a block comment spaced by a no-break space' => ["/*\u{00A0}FIXME the total */", 'FIXME'];
        yield 'a block comment spaced by an ideographic space' => ["/*\u{3000}XXX the total */", 'XXX'];
        yield 'a line comment spaced by a byte order mark' => ["//\u{FEFF}TODO show the total", 'TODO'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function javaScriptPaths(): iterable
    {
        yield 'a script' => ['assets/order.js'];
        yield 'a module' => ['tests/Core/Dashboard/UseCase/order.test.mjs'];
        yield 'a CommonJS script' => ['tools/order.cjs'];
    }

    #[DataProvider('addedJavaScriptMarkerComments')]
    public function testAnAddedMarkerInAJavaScriptCommentFailsNamingTheFileTheLineAndHowToComply(string $comment, string $marker): void
    {
        $sandbox = $this->changeWithFile('assets/order.js', $this->scriptWithLine($comment));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('assets/order.js:3: '.$marker.' comment left in the change; finish the work, or open an issue and write TODO(#<issue>): <reason>', $run->findingsOf('left-undone'));
    }

    #[DataProvider('javaScriptPaths')]
    public function testAnAddedMarkerIsReportedInEveryJavaScriptFileKind(string $path): void
    {
        $sandbox = $this->changeWithFile($path, $this->scriptWithLine('// TODO: show the total'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString($path.':3: TODO comment', $run->findingsOf('left-undone'));
    }

    public function testAMarkerOnTheSecondLineOfAMultiLineBlockCommentIsNamedByItsOwnLine(): void
    {
        $sandbox = $this->changeWithFile('assets/order.js', $this->scriptWithLine("/*\n * FIXME the total\n */"));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('assets/order.js:4: FIXME comment', $run->findingsOf('left-undone'));
    }

    public function testABlockCommentLineIndentedByANoBreakSpaceIsReported(): void
    {
        $sandbox = $this->changeWithFile('assets/order.js', $this->scriptWithLine("/*\n\u{00A0}* FIXME the total\n */"));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('assets/order.js:4: FIXME comment', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function javaScriptLinesWithoutALeftUndoneComment(): iterable
    {
        yield 'a marker in a single-quoted string' => ["const label = '// TODO: show the total';"];
        yield 'a marker in a double-quoted string' => ['const label = "/* FIXME the total */";'];
        yield 'a marker in a template literal' => ['const label = `// XXX ${order.total}`;'];
        yield 'a marker in a regular expression' => ['const found = /\/\/ TODO/.test(source);'];
        yield 'a marker behind a hash' => ['/* # TODO is shell syntax */'];
        yield 'a reasoned exception' => ['// TODO(#123): show the total once the forge sends it'];
    }

    #[DataProvider('javaScriptLinesWithoutALeftUndoneComment')]
    public function testAJavaScriptLineWithoutALeftUndoneCommentPasses(string $line): void
    {
        $sandbox = $this->changeWithFile('assets/order.js', $this->scriptWithLine($line));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAJavaScriptMarkerCommentThatExistedBeforeTheChangeIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('assets/order.js', $this->scriptWithLine('// TODO: show the total'));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('assets/order.js', $this->scriptWithLine("// TODO: show the total\nconst count = 0;"));
        $sandbox->commitAll('feat: show the count');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAMarkerOnAnAddedLineInsideAStringOpenedOnAnUnchangedLineIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('assets/order.js', "const label = `\nend`;\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('assets/order.js', "const label = `\n// TODO: show the total\nend`;\n");
        $sandbox->commitAll('feat: label the total');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAJavaScriptMarkerCommentInAFileNotYetCommittedFails(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('assets/order.js', $this->scriptWithLine('// TODO: show the total'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('assets/order.js:3: TODO comment', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function javaScriptPathsThatAreNotScanned(): iterable
    {
        yield 'a test fixture' => ['tests/Fixtures/order.js'];
        yield 'a gate fixture' => ['tests/Gates/Fixtures/clean/assets/order.js'];
        yield 'a top-level fixture' => ['fixtures/sample-repository/assets/order.js'];
        yield 'a vendored script' => ['vendor/acme/shop/assets/order.js'];
        yield 'an installed package' => ['node_modules/acme/order.js'];
        yield 'a file named like a script' => ['assets/order.js.dist'];
        yield 'a JSON file' => ['assets/order.json'];
    }

    #[DataProvider('javaScriptPathsThatAreNotScanned')]
    public function testAJavaScriptMarkerCommentOutsideTheScopeIsNotScanned(string $path): void
    {
        $sandbox = $this->changeWithFile($path, $this->scriptWithLine('// TODO: show the total'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAPhpMarkerCommentIsStillReportedBesideAJavaScriptOne(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('assets/order.js', $this->scriptWithLine('// TODO: show the total'));
        $sandbox->write('src/Order.php', "<?php\n\n// FIXME the total\nfinal class Order {}\n");
        $sandbox->commitAll('feat: add the order');

        $findings = $sandbox->runGate(new LeftUndoneGate())->findingsOf('left-undone');

        self::assertStringContainsString('assets/order.js:3: TODO comment', $findings);
        self::assertStringContainsString('src/Order.php:3: FIXME comment', $findings);
    }

    private function scriptWithLine(string $line): string
    {
        return "import { order } from './order-data.js';\n\n".$line."\n";
    }

    private function changeWithFile(string $path, string $contents): Sandbox
    {
        $sandbox = $this->startedChange();
        $sandbox->write($path, $contents);
        $sandbox->commitAll('feat: add a file');

        return $sandbox;
    }

    private function startedChange(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "# Shop\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        return $sandbox;
    }
}
