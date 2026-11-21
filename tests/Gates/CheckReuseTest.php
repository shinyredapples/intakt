<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\GateSlots;
use Intakt\Gates\JsonNode;
use Intakt\Gates\PassedTree;
use Intakt\Gates\TreeFingerprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(CheckCommand::class)]
#[UsesClass(PassedTree::class)]
#[UsesClass(TreeFingerprint::class)]
#[UsesClass(JsonNode::class)]
final class CheckReuseTest extends SandboxTestCase
{
    private const string REUSED = '{"result":"pass","failed":[],"reused":"'.PassedTree::RECORD.'"}';

    public function testASecondCheckOfAnIdenticalTreePrintsAPassingSummaryNamingTheRecordAndStartsNoGate(): void
    {
        $sandbox = $this->committedSandbox("clean\n");
        $first = $this->check($sandbox);

        $second = $this->check($sandbox);

        self::assertSame([0, 0], [$first->exitCode(), $second->exitCode()], $first->output().$second->output());
        self::assertTrue($first->passed('dashes'), $first->output());
        self::assertSame([self::REUSED], $second->lines(), $second->output());
    }

    public function testACheckAfterAnEditRunsTheGatesAgain(): void
    {
        $sandbox = $this->committedSandbox("clean\n");
        $this->check($sandbox);
        $sandbox->write('README.md', "edited\n");

        $run = $this->check($sandbox);

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertTrue($run->passed('dashes'), $run->output());
        self::assertStringNotContainsString('"reused"', $run->output());
    }

    public function testAFailedCheckIsNeverReused(): void
    {
        $sandbox = $this->committedSandbox("a long dash \u{2014} here\n");
        $this->check($sandbox);

        $run = $this->check($sandbox);

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('dashes'), $run->output());
        self::assertFileDoesNotExist($sandbox->root.'/'.PassedTree::RECORD);
    }

    private function check(Sandbox $sandbox): CheckRun
    {
        return $sandbox->checkWith([GateSlots::VARIABLE => '1'], '--only=dashes');
    }

    private function committedSandbox(string $readme): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', $readme);
        $sandbox->commitAll('feat: start');

        return $sandbox;
    }
}
