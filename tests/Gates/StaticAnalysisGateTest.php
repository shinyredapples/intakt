<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(StaticAnalysisGate::class)]
final class StaticAnalysisGateTest extends SandboxTestCase
{
    public function testANonBooleanConditionFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'loose-condition', 'if.condNotBoolean');
    }

    public function testADeprecatedCallFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-call', 'function.deprecated');
    }

    public function testAMethodAboveTheCognitiveComplexityLimitFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'too-complex-method', 'complexity.functionLike');
    }

    public function testAClassAboveTheCognitiveComplexityLimitFailsStaticAnalysisEvenWhenEveryMethodIsSimple(): void
    {
        $this->assertRejectedBy('phpstan', 'class-too-complex', 'complexity.classLike');
    }

    public function testStaticAnalysisRunsAtMaxLevelWithoutABaseline(): void
    {
        $configuration = (string) file_get_contents(dirname(__DIR__, 2).'/config/gates/phpstan.neon');

        self::assertStringContainsString('level: max', $configuration);
        self::assertStringNotContainsStringIgnoringCase('baseline', $configuration);
        self::assertStringNotContainsString('ignoreErrors', $configuration);
    }
}
