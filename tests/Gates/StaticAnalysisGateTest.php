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

    // because PHPUnit builds a new instance per case, the one run is shared through the class, so a case selected alone with --filter still makes it
    private static ?CheckRun $runOverEveryFixture = null;

    public function testStaticAnalysisOfEveryFixtureFailsInOneRun(): void
    {
        $run = $this->runOverEveryFixture();

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('phpstan'), $run->output());
    }

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

    private function runOverEveryFixture(): CheckRun
    {
        if (self::$runOverEveryFixture instanceof CheckRun) {
            return self::$runOverEveryFixture;
        }

        $sandbox = Sandbox::withFixturesInFolders(...self::FIXTURES);

        try {
            return self::$runOverEveryFixture = $sandbox->check('--only=phpstan');
        } finally {
            $sandbox->remove();
        }
    }

    private function assertRejectedInItsFolder(string $fixture, string $expectedFinding): void
    {
        self::assertMatchesRegularExpression('~^'.preg_quote($fixture.'/src/', '~').'\S+ '.preg_quote($expectedFinding, '~').' ~m', $this->runOverEveryFixture()->findingsOf('phpstan'));
    }
}
