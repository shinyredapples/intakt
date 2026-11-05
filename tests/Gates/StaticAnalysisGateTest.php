<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StaticAnalysisGate::class)]
final class StaticAnalysisGateTest extends TestCase
{
    // every case asks the gate's configuration about one file, so one run answers them all, each fixture in its own folder
    private const array FIXTURES = ['loose-condition', 'deprecated-call', 'too-complex-method', 'class-too-complex'];

    private static ?CheckRun $run = null;

    public function testANonBooleanConditionFailsStaticAnalysis(): void
    {
        $this->assertRejectedInItsFolder('loose-condition', 'if.condNotBoolean');
    }

    public function testADeprecatedCallFailsStaticAnalysis(): void
    {
        $this->assertRejectedInItsFolder('deprecated-call', 'function.deprecated');
    }

    public function testAMethodAboveTheCognitiveComplexityLimitFailsStaticAnalysis(): void
    {
        $this->assertRejectedInItsFolder('too-complex-method', 'complexity.functionLike');
    }

    public function testAClassAboveTheCognitiveComplexityLimitFailsStaticAnalysisEvenWhenEveryMethodIsSimple(): void
    {
        $this->assertRejectedInItsFolder('class-too-complex', 'complexity.classLike');
    }

    public function testStaticAnalysisRunsAtMaxLevelWithoutABaseline(): void
    {
        $configuration = (string) file_get_contents(dirname(__DIR__, 2).'/config/gates/phpstan.neon');

        self::assertStringContainsString('level: max', $configuration);
        self::assertStringNotContainsStringIgnoringCase('baseline', $configuration);
        self::assertStringNotContainsString('ignoreErrors', $configuration);
    }

    private function assertRejectedInItsFolder(string $fixture, string $expectedFinding): void
    {
        $run = $this->sharedRun();

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertMatchesRegularExpression('~^'.preg_quote($fixture.'/src/', '~').'\S+ '.preg_quote($expectedFinding, '~').' ~m', $run->findingsOf('phpstan'));
    }

    // the first case that asks pays for the run, so the test report shows its time
    private function sharedRun(): CheckRun
    {
        if (!self::$run instanceof CheckRun) {
            $sandbox = Sandbox::withFixturesInFolders(...self::FIXTURES);
            self::$run = $sandbox->check('--only=phpstan');
            $sandbox->remove();
        }

        return self::$run;
    }
}
