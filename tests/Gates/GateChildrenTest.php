<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\GateChildren;
use Intakt\Gates\GateReport;
use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(GateChildren::class)]
#[CoversClass(CheckCommand::class)]
#[UsesClass(GateReport::class)]
final class GateChildrenTest extends SandboxTestCase
{
    public function testASlowPassingGateAndAFastFailingGateAreReportedInGateOrder(): void
    {
        $run = $this->checkWithFakeGates('--only=phpstan,cs');

        self::assertSame([
            '{"gate":"cs","ok":true,"findings":[],"omitted":0}',
            '{"gate":"phpstan","ok":false,"findings":["src/Report.php:3 broken"],"omitted":0}',
            '{"result":"fail","failed":["phpstan"]}',
        ], $run->lines());
        self::assertSame(1, $run->exitCode());
    }

    public function testAGateWhoseChildCrashesFailsWithTheChildsOutput(): void
    {
        $run = $this->checkWithFakeGates('--only=rector');

        self::assertTrue($run->failed('rector'), $run->output());
        self::assertStringContainsString('exit code 255', $run->findingsOf('rector'));
        self::assertStringContainsString('Fatal error: the rector child crashed', $run->findingsOf('rector'));
        self::assertSame(1, $run->exitCode());
    }

    // Because the children run the home's own child entry, a home whose entry fakes the gates fixes their timing and outcome.
    private function checkWithFakeGates(string ...$arguments): CheckRun
    {
        $real = dirname(__DIR__, 2);
        $home = $this->sandboxWith('gate-children');
        $home->write('bin/check', (string) file_get_contents($real.'/bin/check'));
        $home->symlink('vendor', $real.'/vendor');
        $repository = $this->emptySandbox();

        return new CheckRun(new ProcessRunner()->run(['php', $home->root.'/bin/check', '--root='.$repository->root, ...array_values($arguments)], $home->root));
    }
}
