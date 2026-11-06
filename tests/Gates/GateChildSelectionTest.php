<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CheckCommand::class)]
final class GateChildSelectionTest extends SandboxTestCase
{
    public function testAGateChildRunsAGateThatTheArgumentsOfBinCheckSelect(): void
    {
        $child = $this->runGateChild('dashes', '--only=dashes');

        self::assertSame('{"gate":"dashes","ok":true,"findings":[],"omitted":0}', trim($child->output), $child->errors);
        self::assertSame(0, $child->exitCode);
    }

    public function testAGateChildRefusesAGateThatTheArgumentsOfBinCheckLeaveOut(): void
    {
        $child = $this->runGateChild('dashes', '--skip=dashes');

        self::assertSame('{"error":"unknown gate dashes"}', trim($child->output));
        self::assertSame(2, $child->exitCode);
    }

    private function runGateChild(string $gate, string $argument): ProcessResult
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "plain words\n");
        $sandbox->commitAll('chore: start');

        return new ProcessRunner()->run(['php', dirname(__DIR__, 2).'/config/gates/run-gate.php', $gate, $argument, '--root='.$sandbox->root], $sandbox->root);
    }
}
