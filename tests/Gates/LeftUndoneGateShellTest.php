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
use Intakt\Gates\ShellLiterals;
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
#[UsesClass(ShellLiterals::class)]
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
        yield 'a TODO comment' => ['# TODO: check the exit code', 'TODO'];
        yield 'a FIXME comment after a command' => ['curl -fsS localhost # FIXME retry once', 'FIXME'];
        yield 'an indented XXX comment' => ['    # xxx breaks without a network', 'xxx'];
    }

    #[DataProvider('addedShellMarkerComments')]
    public function testAnAddedMarkerInAShellCommentFailsNamingTheFileTheLineAndHowToComply(string $comment, string $marker): void
    {
        $sandbox = $this->changeWithFile('docker/healthcheck.sh', $this->scriptWithLine($comment));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('docker/healthcheck.sh:3: '.$marker.' comment left in the change; finish the work, or open an issue and write TODO(#<issue>): <reason>', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shellLinesWithoutALeftUndoneComment(): iterable
    {
        yield 'a marker in a single-quoted string' => ["echo '# TODO: check the exit code'"];
        yield 'a marker in a double-quoted string' => ['echo "# FIXME retry once"'];
        yield 'a marker after the hash of a parameter length' => ['echo "${#items[@]}" ${#TODO}'];
        yield 'a marker in a here-document' => ["cat <<EOF\n# TODO: check the exit code\nEOF"];
        yield 'a reasoned exception' => ['# TODO(#123): drop once the image ships curl'];
    }

    #[DataProvider('shellLinesWithoutALeftUndoneComment')]
    public function testAShellLineWithoutALeftUndoneCommentPasses(string $line): void
    {
        $sandbox = $this->changeWithFile('docker/healthcheck.sh', $this->scriptWithLine($line));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAShellMarkerCommentThatExistedBeforeTheChangeIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('docker/healthcheck.sh', $this->scriptWithLine('# TODO: check the exit code'));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('docker/healthcheck.sh', $this->scriptWithLine("# TODO: check the exit code\nexit 0"));
        $sandbox->commitAll('feat: exit cleanly');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAMarkerOnAnAddedLineInsideAHereDocumentOpenedOnAnUnchangedLineIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('docker/healthcheck.sh', "cat <<EOF\nEOF\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('docker/healthcheck.sh', "cat <<EOF\n# TODO: check the exit code\nEOF\n");
        $sandbox->commitAll('feat: print a note');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAShellMarkerCommentInAFileNotYetCommittedFails(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('docker/healthcheck.sh', $this->scriptWithLine('# TODO: check the exit code'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('docker/healthcheck.sh:3: TODO comment', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shellPathsThatAreNotScanned(): iterable
    {
        yield 'a test fixture' => ['tests/Fixtures/healthcheck.sh'];
        yield 'a gate fixture' => ['tests/Gates/Fixtures/clean/docker/healthcheck.sh'];
        yield 'a top-level fixture' => ['fixtures/sample-repository/healthcheck.sh'];
        yield 'a vendored script' => ['vendor/acme/shop/bin/healthcheck.sh'];
        yield 'an installed package' => ['node_modules/acme/healthcheck.sh'];
        yield 'a file named like a script' => ['docker/healthcheck.sh.dist'];
        yield 'a script without the extension' => ['docker/healthcheck'];
    }

    #[DataProvider('shellPathsThatAreNotScanned')]
    public function testAShellMarkerCommentOutsideTheScopeIsNotScanned(string $path): void
    {
        $sandbox = $this->changeWithFile($path, $this->scriptWithLine('# TODO: check the exit code'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAPhpMarkerCommentIsStillReportedBesideAShellOne(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('docker/healthcheck.sh', $this->scriptWithLine('# TODO: check the exit code'));
        $sandbox->write('src/Order.php', "<?php\n\n// FIXME the total\nfinal class Order {}\n");
        $sandbox->commitAll('feat: add the order');

        $findings = $sandbox->runGate(new LeftUndoneGate())->findingsOf('left-undone');

        self::assertStringContainsString('docker/healthcheck.sh:3: TODO comment', $findings);
        self::assertStringContainsString('src/Order.php:3: FIXME comment', $findings);
    }

    public function testTheGateRunFromBinCheckReportsAShellMarker(): void
    {
        $sandbox = $this->changeWithFile('docker/healthcheck.sh', $this->scriptWithLine('# TODO: check the exit code'));

        $run = $sandbox->check('--only=left-undone');

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('docker/healthcheck.sh:3: TODO comment left in the change', $run->findingsOf('left-undone'));
    }

    private function scriptWithLine(string $line): string
    {
        return "#!/bin/sh\nset -eu\n".$line."\n";
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
