<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\AddedLines;
use Intakt\Gates\CommentLanguages;
use Intakt\Gates\JavaScriptComments;
use Intakt\Gates\LeftUndoneCode;
use Intakt\Gates\LeftUndoneComments;
use Intakt\Gates\LeftUndoneGate;
use Intakt\Gates\LeftUndoneTokens;
use Intakt\Gates\PhpComments;
use Intakt\Gates\Scope;
use Intakt\Gates\ShellComments;
use Intakt\Gates\TwigComments;
use Intakt\Gates\Workspace;
use Intakt\Gates\YamlComments;
use Intakt\Gates\YamlLexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(LeftUndoneGate::class)]
#[CoversClass(CommentLanguages::class)]
#[CoversClass(YamlComments::class)]
#[CoversClass(LeftUndoneComments::class)]
#[UsesClass(YamlLexer::class)]
#[UsesClass(PhpComments::class)]
#[UsesClass(TwigComments::class)]
#[UsesClass(JavaScriptComments::class)]
#[UsesClass(ShellComments::class)]
#[UsesClass(AddedLines::class)]
#[UsesClass(LeftUndoneCode::class)]
#[UsesClass(LeftUndoneTokens::class)]
#[UsesClass(Workspace::class)]
#[UsesClass(Scope::class)]
#[UsesClass(Sandbox::class)]
final class LeftUndoneGateYamlTest extends SandboxTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addedYamlMarkerComments(): iterable
    {
        yield 'a TODO comment line' => ['# TODO name the queue', 'TODO'];
        yield 'a FIXME comment after a value' => ['retries: 3 # FIXME raise it for the forge', 'FIXME'];
        yield 'an XXX comment after a quoted value' => ["name: 'intakt' # XXX breaks on a rename", 'XXX'];
        yield 'a lower-case marker' => ['# todo: pin the version', 'todo'];
    }

    #[DataProvider('addedYamlMarkerComments')]
    public function testAnAddedMarkerInAYamlCommentFailsNamingTheFileTheLineAndHowToComply(string $comment, string $marker): void
    {
        $sandbox = $this->changeWithFile('config/packages/shop.yaml', $this->documentWithLine($comment));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('config/packages/shop.yaml:3: '.$marker.' comment left in the change; finish the work, or open an issue and write TODO(#<issue>): <reason>', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function yamlPaths(): iterable
    {
        yield 'a .yaml file' => ['config/packages/shop.yaml'];
        yield 'a .yml file' => ['.github/workflows/shop.yml'];
    }

    #[DataProvider('yamlPaths')]
    public function testAnAddedMarkerIsReportedInEveryYamlFileKind(string $path): void
    {
        $sandbox = $this->changeWithFile($path, $this->documentWithLine('# TODO name the queue'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString($path.':3: TODO comment', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function yamlLinesWithoutALeftUndoneComment(): iterable
    {
        yield 'a marker in a single-quoted value' => ["name: '# TODO name the queue'"];
        yield 'a marker in a double-quoted value' => ['name: "x # FIXME raise it"'];
        yield 'a marker after a hash inside a plain value' => ['name: a#TODO'];
        yield 'a marker in a block scalar' => ["run: |\n  # TODO name the queue"];
        yield 'a reasoned exception' => ['retries: 3 # TODO(#123): raise it once the forge allows it'];
    }

    #[DataProvider('yamlLinesWithoutALeftUndoneComment')]
    public function testAYamlLineWithoutALeftUndoneCommentPasses(string $line): void
    {
        $sandbox = $this->changeWithFile('config/packages/shop.yaml', $this->documentWithLine($line));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAYamlMarkerCommentThatExistedBeforeTheChangeIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('config/packages/shop.yaml', $this->documentWithLine('# TODO name the queue'));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('config/packages/shop.yaml', $this->documentWithLine("# TODO name the queue\n  retries: 3"));
        $sandbox->commitAll('feat: retry the shop');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function yamlPathsThatAreNotScanned(): iterable
    {
        yield 'a test fixture' => ['tests/Fixtures/shop.yaml'];
        yield 'a gate fixture' => ['tests/Gates/Fixtures/clean/shop.yaml'];
        yield 'a vendored file' => ['vendor/acme/shop.yml'];
        yield 'a file named like YAML' => ['config/shop.yaml.dist'];
    }

    #[DataProvider('yamlPathsThatAreNotScanned')]
    public function testAYamlMarkerCommentOutsideTheScopeIsNotScanned(string $path): void
    {
        $sandbox = $this->changeWithFile($path, $this->documentWithLine('# TODO name the queue'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAYamlMarkerCommentFailsTheCheck(): void
    {
        $sandbox = $this->changeWithFile('config/packages/shop.yaml', $this->documentWithLine('# TODO name the queue'));

        $run = $sandbox->check('--only=left-undone');

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('config/packages/shop.yaml:3: TODO comment', $run->findingsOf('left-undone'));
    }

    private function documentWithLine(string $line): string
    {
        return "shop:\n  enabled: true\n".$line."\n";
    }

    private function changeWithFile(string $path, string $contents): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "# Shop\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write($path, $contents);
        $sandbox->commitAll('feat: add a file');

        return $sandbox;
    }
}
