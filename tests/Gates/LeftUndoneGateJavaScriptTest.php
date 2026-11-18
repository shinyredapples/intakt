<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\AddedLines;
use Intakt\Gates\CommentLanguages;
use Intakt\Gates\JavaScriptComments;
use Intakt\Gates\JavaScriptSlash;
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
#[UsesClass(PhpComments::class)]
#[UsesClass(JavaScriptSlash::class)]
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
     * @return iterable<string, array{string, string, string}>
     */
    public static function addedJavaScriptMarkerComments(): iterable
    {
        yield 'a TODO line comment' => ['assets/search.js', '// TODO: debounce the input', 'TODO'];
        yield 'a FIXME block comment' => ['assets/search.js', '/* FIXME the count is off by one */', 'FIXME'];
        yield 'an XXX doc comment' => ['assets/search.js', '/** XXX breaks on an empty query */', 'XXX'];
        yield 'a comment behind code' => ['assets/search.js', 'const limit = 10; // TODO read it from the page', 'TODO'];
        yield 'a comment in a module' => ['assets/search.mjs', '// TODO: debounce the input', 'TODO'];
        yield 'a comment in a CommonJS module' => ['assets/search.cjs', '// TODO: debounce the input', 'TODO'];
    }

    #[DataProvider('addedJavaScriptMarkerComments')]
    public function testAnAddedMarkerInAJavaScriptCommentFailsNamingTheFileTheLineAndHowToComply(string $path, string $line, string $marker): void
    {
        $sandbox = $this->changeWithFile($path, $this->scriptWithLine($line));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString($path.':3: '.$marker.' comment left in the change; finish the work, or open an issue and write TODO(#<issue>): <reason>', $run->findingsOf('left-undone'));
    }

    public function testAMarkerOnAStarredLineOfAJavaScriptDocCommentIsNamedByItsOwnLine(): void
    {
        $sandbox = $this->changeWithFile('assets/search.js', $this->scriptWithLine("/**\n * Searches.\n * FIXME the count\n */"));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('assets/search.js:5: FIXME comment', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function javaScriptLinesWithoutALeftUndoneComment(): iterable
    {
        yield 'a marker in a string' => ["const label = 'TODO: debounce the input';"];
        yield 'a comment in a string' => ['const label = "// TODO debounce the input";'];
        yield 'a comment in a template literal' => ['const label = `/* FIXME ${count} */`;'];
        yield 'a comment in a regular expression' => ['const pattern = /\/\/ TODO/;'];
        yield 'a reasoned exception' => ['// TODO(#123): debounce the input once the search endpoint is cached'];
        yield 'a not-implemented throw' => ["throw new Error('Not implemented');"];
    }

    #[DataProvider('javaScriptLinesWithoutALeftUndoneComment')]
    public function testAJavaScriptLineWithoutALeftUndoneCommentPasses(string $line): void
    {
        $sandbox = $this->changeWithFile('assets/search.js', $this->scriptWithLine($line));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAJavaScriptMarkerCommentThatExistedBeforeTheChangeIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('assets/search.js', $this->scriptWithLine('// TODO: debounce the input'));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('assets/search.js', $this->scriptWithLine("// TODO: debounce the input\nconst limit = 10;"));
        $sandbox->commitAll('feat: limit the results');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAJavaScriptMarkerCommentInAFileNotYetCommittedFails(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('assets/search.js', $this->scriptWithLine('// TODO: debounce the input'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('assets/search.js:3: TODO comment', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function javaScriptPathsThatAreNotScanned(): iterable
    {
        yield 'a test fixture' => ['tests/Fixtures/search.js'];
        yield 'a gate fixture' => ['tests/Gates/Fixtures/clean/assets/search.js'];
        yield 'a top-level fixture' => ['fixtures/sample-repository/base/src/cart.js'];
        yield 'an installed package' => ['node_modules/acme/index.js'];
        yield 'a vendored script' => ['vendor/acme/shop/assets/search.js'];
        yield 'a file named like a script' => ['assets/search.js.map'];
        yield 'a JSON file' => ['assets/controllers.json'];
    }

    #[DataProvider('javaScriptPathsThatAreNotScanned')]
    public function testAJavaScriptMarkerCommentOutsideTheScopeIsNotScanned(string $path): void
    {
        $sandbox = $this->changeWithFile($path, $this->scriptWithLine('// TODO: debounce the input'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testPhpAndTwigMarkerCommentsAreStillReportedBesideAJavaScriptOne(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('assets/search.js', $this->scriptWithLine('// TODO: debounce the input'));
        $sandbox->write('templates/order.html.twig', "<p>\n{# XXX show the total #}\n");
        $sandbox->write('src/Order.php', "<?php\n\n// FIXME the total\nfinal class Order {}\n");
        $sandbox->commitAll('feat: add the search');

        $findings = $sandbox->runGate(new LeftUndoneGate())->findingsOf('left-undone');

        self::assertStringContainsString('assets/search.js:3: TODO comment', $findings);
        self::assertStringContainsString('templates/order.html.twig:2: XXX comment', $findings);
        self::assertStringContainsString('src/Order.php:3: FIXME comment', $findings);
    }

    private function scriptWithLine(string $line): string
    {
        return "import { debounce } from './debounce.js';\n\n".$line."\n";
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
