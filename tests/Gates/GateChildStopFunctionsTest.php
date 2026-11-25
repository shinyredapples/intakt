<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\ProcessGroup;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\RequiredTools;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(RequiredTools::class)]
#[UsesClass(CheckCommand::class)]
#[UsesClass(ProcessGroup::class)]
#[UsesClass(ProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
final class GateChildStopFunctionsTest extends SandboxTestCase
{
    public function testBinCheckRefusesToStartAGateWhenTheStopCannotAskWhetherTheLauncherIsStillItsChild(): void
    {
        $check = $this->runBinCheck('pcntl_waitid');

        self::assertSame('{"error":"missing tools","missing":["'.RequiredTools::notFound('PHP extension pcntl').'"]}', trim($check->output));
        self::assertSame(2, $check->exitCode);
    }

    public function testBinCheckRefusesToStartAGateWhenTheStopCannotReadTheLauncherSGroup(): void
    {
        $check = $this->runBinCheck('posix_getpgid');

        self::assertSame('{"error":"missing tools","missing":["'.RequiredTools::notFound('PHP extension posix').'"]}', trim($check->output));
        self::assertSame(2, $check->exitCode);
    }

    private function runBinCheck(string $disabledFunction): ProcessResult
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "plain words\n");
        $sandbox->commitAll('chore: start');

        return new ProcessRunner()->run(
            ['php', '-d', 'disable_functions='.$disabledFunction, dirname(__DIR__, 2).'/bin/check', '--only=dashes', '--root='.$sandbox->root],
            $sandbox->root,
        );
    }
}
