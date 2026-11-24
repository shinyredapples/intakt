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
#[UsesClass(ShellComments::class)]
#[UsesClass(JavaScriptComments::class)]
#[UsesClass(PhpComments::class)]
#[UsesClass(TwigComments::class)]
#[UsesClass(AddedLines::class)]
#[UsesClass(LeftUndoneCode::class)]
#[UsesClass(LeftUndoneTokens::class)]
#[UsesClass(Workspace::class)]
#[UsesClass(Scope::class)]
#[UsesClass(Sandbox::class)]
final class LeftUndoneGateYamlTest extends SandboxTestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function addedYamlMarkerComments(): iterable
    {
        yield 'a TODO comment line in a .yaml file' => ['config/packages/shop.yaml', '# TODO: drop the legacy route', 'TODO'];
        yield 'a FIXME comment after a value in a .yml file' => ['.github/workflows/ci.yml', 'timeout: 10 # FIXME raise it for the slow runner', 'FIXME'];
        yield 'an XXX comment with repeated hashes' => ['compose.yaml', '## XXX breaks without a volume', 'XXX'];
        yield 'a TODO after a block scalar' => ['.github/workflows/ci.yml', "run: |\n  echo '# not a comment'\n# TODO cache the vendor folder", 'TODO'];
    }

    #[DataProvider('addedYamlMarkerComments')]
    public function testAnAddedMarkerInAYamlCommentFailsNamingTheFileTheLineAndHowToComply(string $path, string $comment, string $marker): void
    {
        $sandbox = $this->changeWithFile($path, $this->yamlWithLines($comment));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString(
            $path.':'.(2 + substr_count($comment, "\n")).': '.$marker.' comment left in the change; finish the work, or open an issue and write TODO(#<issue>): <reason>',
            $run->findingsOf('left-undone'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function yamlLinesWithoutALeftUndoneComment(): iterable
    {
        yield 'a marker in a single-quoted value' => ["title: '# TODO: drop the legacy route'"];
        yield 'a marker in a double-quoted value' => ['title: "# FIXME raise it"'];
        yield 'a hash that does not start a comment in a plain value' => ['url: https://localhost/#TODO'];
        yield 'a marker in a literal block scalar' => ["run: |\n  # TODO is a line of the script"];
        yield 'a marker in a block scalar behind an anchor before a compact key' => ["steps:\n  - &step run: |\n      # TODO is a line of the script"];
        yield 'a reasoned exception' => ['# TODO(#123): drop the legacy route once the forge sends the new one'];
    }

    #[DataProvider('yamlLinesWithoutALeftUndoneComment')]
    public function testAYamlLineWithoutALeftUndoneCommentPasses(string $line): void
    {
        $sandbox = $this->changeWithFile('config/packages/shop.yaml', $this->yamlWithLines($line));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAYamlMarkerCommentThatExistedBeforeTheChangeIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('config/packages/shop.yaml', $this->yamlWithLines('# TODO: drop the legacy route'));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('config/packages/shop.yaml', $this->yamlWithLines("# TODO: drop the legacy route\nroute: /shop"));
        $sandbox->commitAll('feat: name the route');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAMarkerOnAnAddedLineInsideABlockScalarOpenedOnAnUnchangedLineIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('config/packages/shop.yaml', "run: |\n  echo a\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('config/packages/shop.yaml', "run: |\n  # TODO is a line of the script\n  echo a\n");
        $sandbox->commitAll('feat: tell more');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function yamlPathsThatAreNotScanned(): iterable
    {
        yield 'a test fixture' => ['tests/Fixtures/shop.yaml'];
        yield 'a gate fixture' => ['tests/Gates/Fixtures/sample/shop.yml'];
        yield 'a top-level fixture' => ['fixtures/sample-repository/shop.yaml'];
        yield 'a vendored file' => ['vendor/acme/shop/config/shop.yaml'];
        yield 'a file named like a YAML file' => ['config/shop.yaml.dist'];
    }

    #[DataProvider('yamlPathsThatAreNotScanned')]
    public function testAYamlMarkerCommentOutsideTheScopeIsNotScanned(string $path): void
    {
        $sandbox = $this->changeWithFile($path, $this->yamlWithLines('# TODO: drop the legacy route'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    private function yamlWithLines(string $lines): string
    {
        return "shop:\n".$lines."\n";
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
