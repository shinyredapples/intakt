<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

#[CoversClass(StaticAnalysisGate::class)]
final class StaticAnalysisGateTest extends TestCase
{
    // every case asks the gate's configuration about one file, so one run answers them all, each fixture in its own folder
    private const array FIXTURES = ['loose-condition', 'deprecated-call', 'too-complex-method', 'class-too-complex'];

    public function testStaticAnalysisOfEveryFixtureFailsInOneRun(): CheckRun
    {
        $sandbox = Sandbox::withFixturesInFolders(...self::FIXTURES);
        $run = $sandbox->check('--only=phpstan');
        $sandbox->remove();

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('phpstan'), $run->output());

        return $run;
    }

    #[Depends('testStaticAnalysisOfEveryFixtureFailsInOneRun')]
    public function testANonBooleanConditionFailsStaticAnalysis(CheckRun $run): void
    {
        $this->assertRejectedInItsFolder($run, 'loose-condition', 'if.condNotBoolean');
    }

    #[Depends('testStaticAnalysisOfEveryFixtureFailsInOneRun')]
    public function testADeprecatedCallFailsStaticAnalysis(CheckRun $run): void
    {
        $this->assertRejectedInItsFolder($run, 'deprecated-call', 'function.deprecated');
    }

    #[Depends('testStaticAnalysisOfEveryFixtureFailsInOneRun')]
    public function testAMethodAboveTheCognitiveComplexityLimitFailsStaticAnalysis(CheckRun $run): void
    {
        $this->assertRejectedInItsFolder($run, 'too-complex-method', 'complexity.functionLike');
    }

    #[Depends('testStaticAnalysisOfEveryFixtureFailsInOneRun')]
    public function testAClassAboveTheCognitiveComplexityLimitFailsStaticAnalysisEvenWhenEveryMethodIsSimple(CheckRun $run): void
    {
        $this->assertRejectedInItsFolder($run, 'class-too-complex', 'complexity.classLike');
    }

    public function testStaticAnalysisRunsAtMaxLevelWithoutABaseline(): void
    {
        $configuration = (string) file_get_contents(dirname(__DIR__, 2).'/config/gates/phpstan.neon');

        self::assertStringContainsString('level: max', $configuration);
        self::assertStringNotContainsStringIgnoringCase('baseline', $configuration);
        self::assertStringNotContainsString('ignoreErrors', $configuration);
    }

    private function assertRejectedInItsFolder(CheckRun $run, string $fixture, string $expectedFinding): void
    {
        self::assertMatchesRegularExpression('~^'.preg_quote($fixture.'/src/', '~').'\S+ '.preg_quote($expectedFinding, '~').' ~m', $run->findingsOf('phpstan'));
    }
}
