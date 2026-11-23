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
use Intakt\Gates\YamlComments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(LeftUndoneGate::class)]
#[CoversClass(CommentLanguages::class)]
#[CoversClass(ShellComments::class)]
#[CoversClass(LeftUndoneComments::class)]
#[UsesClass(ShellLexer::class)]
#[UsesClass(PhpComments::class)]
#[UsesClass(TwigComments::class)]
#[UsesClass(JavaScriptComments::class)]
#[UsesClass(YamlComments::class)]
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
        yield 'a TODO comment line' => ['# TODO finish the deploy', 'TODO'];
        yield 'a FIXME comment after a command' => ['make build # FIXME cache the layers', 'FIXME'];
        yield 'an XXX comment after a semicolon' => ['true;# XXX breaks on an empty tag', 'XXX'];
        yield 'a lower-case marker' => ['# todo: pin the version', 'todo'];
    }

    #[DataProvider('addedShellMarkerComments')]
    public function testAnAddedMarkerInAShellCommentFailsNamingTheFileTheLineAndHowToComply(string $comment, string $marker): void
    {
        $sandbox = $this->changeWithFile('docker/deploy.sh', $this->scriptWithLine($comment));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('docker/deploy.sh:3: '.$marker.' comment left in the change; finish the work, or open an issue and write TODO(#<issue>): <reason>', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shellScripts(): iterable
    {
        yield 'a .sh script' => ['docker/deploy.sh', '#!/bin/sh'];
        yield 'a .bash script' => ['tools/deploy.bash', '#!/usr/bin/env bash'];
        yield 'an extensionless script with a sh hashbang' => ['bin/deploy', '#!/bin/sh -e'];
        yield 'an extensionless script with an env bash hashbang' => ['bin/deploy', '#!/usr/bin/env bash'];
        yield 'an extensionless script with an env split-string bash hashbang' => ['bin/deploy', '#!/usr/bin/env -S bash -e'];
    }

    #[DataProvider('shellScripts')]
    public function testAnAddedMarkerIsReportedInEveryShellScriptKind(string $path, string $hashbang): void
    {
        $sandbox = $this->changeWithFile($path, $hashbang."\nset -eu\n# TODO finish the deploy\n");

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString($path.':3: TODO comment', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shellLinesWithoutALeftUndoneComment(): iterable
    {
        yield 'a marker in a single-quoted string' => ["echo '# TODO finish the deploy'"];
        yield 'a marker in a double-quoted string' => ['echo "# FIXME cache the layers"'];
        yield 'a marker in a length expansion' => ['echo ${#TODO}'];
        yield 'a marker after the argument count' => ['echo $# TODO'];
        yield 'a marker in a here-document' => ["cat <<EOF\n# TODO finish the deploy\nEOF"];
        yield 'a reasoned exception' => ['# TODO(#123): drop once the image ships bash 5'];
    }

    #[DataProvider('shellLinesWithoutALeftUndoneComment')]
    public function testAShellLineWithoutALeftUndoneCommentPasses(string $line): void
    {
        $sandbox = $this->changeWithFile('docker/deploy.sh', $this->scriptWithLine($line));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAShellMarkerCommentThatExistedBeforeTheChangeIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('docker/deploy.sh', $this->scriptWithLine('# TODO finish the deploy'));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('docker/deploy.sh', $this->scriptWithLine("# TODO finish the deploy\necho done"));
        $sandbox->commitAll('feat: say when the deploy is done');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filesThatAreNotReadAsShell(): iterable
    {
        yield 'a test fixture' => ['tests/Fixtures/deploy.sh', '#!/bin/sh'];
        yield 'a gate fixture' => ['tests/Gates/Fixtures/clean/deploy.sh', '#!/bin/sh'];
        yield 'a vendored script' => ['vendor/acme/deploy.sh', '#!/bin/sh'];
        yield 'an extensionless script with a node hashbang' => ['bin/deploy', '#!/usr/bin/env node'];
        yield 'an extensionless file without a hashbang' => ['bin/deploy', 'deploy notes'];
        yield 'a file with another extension and a sh hashbang' => ['docs/deploy.txt', '#!/bin/sh'];
        yield 'a hashbang naming a shell-like word' => ['bin/deploy', '#!/usr/bin/fish'];
    }

    #[DataProvider('filesThatAreNotReadAsShell')]
    public function testAMarkerCommentInAFileThatIsNotReadAsShellIsNotReported(string $path, string $firstLine): void
    {
        $sandbox = $this->changeWithFile($path, $firstLine."\n\n# TODO finish the deploy\n");

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAShellMarkerCommentFailsTheCheck(): void
    {
        $sandbox = $this->changeWithFile('docker/deploy.sh', $this->scriptWithLine('# TODO finish the deploy'));

        $run = $sandbox->check('--only=left-undone');

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('docker/deploy.sh:3: TODO comment', $run->findingsOf('left-undone'));
    }

    private function scriptWithLine(string $line): string
    {
        return "#!/bin/sh\n\n".$line."\n";
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
