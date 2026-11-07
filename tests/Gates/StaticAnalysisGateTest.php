<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StaticAnalysisGate::class)]
final class StaticAnalysisGateTest extends TestCase
{
    private const array EXPECTED_FINDINGS = [
        'loose-condition' => 'if.condNotBoolean',
        'deprecated-call' => 'function.deprecated',
        'too-complex-method' => 'complexity.functionLike',
        'class-too-complex' => 'complexity.classLike',
    ];

    /**
     * @return iterable<string, array{string, string, CheckRun}>
     */
    public static function fixturesOfOneRun(): iterable
    {
        // PHPUnit calls a provider once for all its cases, so one run answers every fixture, each in its own folder, and a case still runs alone under --filter
        $sandbox = Sandbox::withFixturesInFolders(...array_keys(self::EXPECTED_FINDINGS));
        $sandbox->borrowKernel();

        try {
            $run = $sandbox->check('--only=phpstan');
        } finally {
            $sandbox->remove();
        }

        foreach (self::EXPECTED_FINDINGS as $fixture => $expectedFinding) {
            yield $fixture => [$fixture, $expectedFinding, $run];
        }
    }

    #[DataProvider('fixturesOfOneRun')]
    public function testEachFixtureFailsStaticAnalysisWithItsOwnFinding(string $fixture, string $expectedFinding, CheckRun $run): void
    {
        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertMatchesRegularExpression('~^'.preg_quote($fixture.'/src/', '~').'\S+ '.preg_quote($expectedFinding, '~').' ~m', $run->findingsOf('phpstan'));
    }

    public function testStaticAnalysisRunsAtMaxLevelWithoutABaseline(): void
    {
        $configuration = (string) file_get_contents(dirname(__DIR__, 2).'/config/gates/phpstan.neon');

        self::assertStringContainsString('level: max', $configuration);
        self::assertStringNotContainsStringIgnoringCase('baseline', $configuration);
        self::assertStringNotContainsString('ignoreErrors', $configuration);
    }
}
