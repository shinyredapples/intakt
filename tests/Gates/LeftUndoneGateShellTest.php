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
use Intakt\Gates\ShellLexer;
use Intakt\Gates\TwigComments;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(LeftUndoneGate::class)]
#[CoversClass(CommentLanguages::class)]
#[CoversClass(ShellComments::class)]
#[CoversClass(LeftUndoneComments::class)]
#[UsesClass(ShellLexer::class)]
#[UsesClass(JavaScriptComments::class)]
#[UsesClass(PhpComments::class)]
#[UsesClass(TwigComments::class)]
#[UsesClass(AddedLines::class)]
#[UsesClass(LeftUndoneCode::class)]
#[UsesClass(LeftUndoneTokens::class)]
#[UsesClass(Workspace::class)]
#[UsesClass(Scope::class)]
#[UsesClass(Sandbox::class)]
final class LeftUndoneGateShellTest extends SandboxTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addedShellMarkerComments(): iterable
    {
        yield 'a TODO comment line' => ['# TODO: retry the download', 'TODO'];
        yield 'a FIXME comment after a command' => ['curl -fsS "$url" # FIXME follow redirects', 'FIXME'];
        yield 'an XXX comment with repeated hashes' => ['## XXX breaks on an empty list', 'XXX'];
        yield 'a TODO after an apostrophe in a quoted expansion' => ["printf '%s\\n' \"\${message:-don't stop}\"\n# TODO stop", 'TODO'];
    }

    #[DataProvider('addedShellMarkerComments')]
    public function testAnAddedMarkerInAShellCommentFailsNamingTheFileTheLineAndHowToComply(string $comment, string $marker): void
    {
        $sandbox = $this->changeWithFile('docker/fetch.sh', $this->scriptWithLines($comment));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString(
            'docker/fetch.sh:'.(2 + substr_count($comment, "\n")).': '.$marker.' comment left in the change; finish the work, or open an issue and write TODO(#<issue>): <reason>',
            $run->findingsOf('left-undone'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shellLinesWithoutALeftUndoneComment(): iterable
    {
        yield 'a marker in a single-quoted string' => ["echo '# TODO: retry the download'"];
        yield 'a marker in a double-quoted string' => ['echo "# FIXME follow redirects"'];
        yield 'a marker behind a length expansion' => ['echo "${#TODO}"'];
        yield 'a marker behind a prefix removal' => ['echo ${path#TODO}'];
        yield 'a reasoned exception' => ['# TODO(#123): retry the download once the mirror is back'];
    }

    #[DataProvider('shellLinesWithoutALeftUndoneComment')]
    public function testAShellLineWithoutALeftUndoneCommentPasses(string $line): void
    {
        $sandbox = $this->changeWithFile('docker/fetch.sh', $this->scriptWithLines($line));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAShellMarkerCommentThatExistedBeforeTheChangeIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('docker/fetch.sh', $this->scriptWithLines('# TODO: retry the download'));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('docker/fetch.sh', $this->scriptWithLines("# TODO: retry the download\necho done"));
        $sandbox->commitAll('feat: say when done');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAMarkerOnAnAddedLineInsideAStringOpenedOnAnUnchangedLineIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('docker/fetch.sh', "echo '\nend'\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('docker/fetch.sh', "echo '\n# TODO: retry the download\nend'\n");
        $sandbox->commitAll('feat: tell more');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shellPathsThatAreNotScanned(): iterable
    {
        yield 'a test fixture' => ['tests/Fixtures/fetch.sh'];
        yield 'a top-level fixture' => ['fixtures/sample-repository/fetch.sh'];
        yield 'a vendored script' => ['vendor/acme/shop/bin/fetch.sh'];
        yield 'a file named like a script' => ['docker/fetch.sh.dist'];
        yield 'a script without an extension' => ['docker/fetch'];
    }

    #[DataProvider('shellPathsThatAreNotScanned')]
    public function testAShellMarkerCommentOutsideTheScopeIsNotScanned(string $path): void
    {
        $sandbox = $this->changeWithFile($path, $this->scriptWithLines('# TODO: retry the download'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    private function scriptWithLines(string $lines): string
    {
        return "#!/bin/sh\n".$lines."\n";
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
