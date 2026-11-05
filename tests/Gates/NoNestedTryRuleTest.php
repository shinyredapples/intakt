<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NoNestedTryRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<NoNestedTryRule>
 */
#[CoversClass(NoNestedTryRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoNestedTryRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): NoNestedTryRule
    {
        return new NoNestedTryRule();
    }

    public function testATryInsideACatchFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'nested-try', 'intakt.nestedTry');
    }

    public function testATryInsideATryFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('try-in-try', 'intakt.nestedTry');
    }

    public function testATryInsideAFinallyFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('try-in-finally', 'intakt.nestedTry');
    }

    public function testATryInAClosureInsideATryPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('try-in-closure');
    }

    public function testTriesInSeparateMethodsPassStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'sibling-tries');
    }
}
