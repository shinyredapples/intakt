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
use Intakt\Gates\ShellHereDocuments;
use Intakt\Gates\ShellLexer;
use Intakt\Gates\TwigComments;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(LeftUndoneGate::class)]
#[CoversClass(ShellLexer::class)]
#[UsesClass(CommentLanguages::class)]
#[UsesClass(ShellComments::class)]
#[UsesClass(ShellHereDocuments::class)]
#[UsesClass(LeftUndoneComments::class)]
#[UsesClass(JavaScriptComments::class)]
#[UsesClass(PhpComments::class)]
#[UsesClass(TwigComments::class)]
#[UsesClass(AddedLines::class)]
#[UsesClass(LeftUndoneCode::class)]
#[UsesClass(LeftUndoneTokens::class)]
#[UsesClass(Workspace::class)]
#[UsesClass(Scope::class)]
#[UsesClass(Sandbox::class)]
final class LeftUndoneGateShellFunctionTest extends SandboxTestCase
{
    public function testAnAddedMarkerCommentAfterAFunctionThatShiftsInArithmeticFails(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "# Shop\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('docker/fetch.sh', "#!/bin/sh\nf() { (( x <<= 1 )); }\n# TODO retry the download\n");
        $sandbox->commitAll('feat: add a file');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('docker/fetch.sh:3: TODO comment left in the change', $run->findingsOf('left-undone'));
    }
}
