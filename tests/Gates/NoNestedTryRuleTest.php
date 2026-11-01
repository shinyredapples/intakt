<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NoNestedTryRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(NoNestedTryRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoNestedTryRuleTest extends SandboxTestCase
{
    public function testATryInsideACatchFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'nested-try', 'intakt.nestedTry');
    }

    public function testATryInsideATryFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'try-in-try', 'intakt.nestedTry');
    }

    public function testATryInsideAFinallyFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'try-in-finally', 'intakt.nestedTry');
    }

    public function testATryInAClosureInsideATryPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'try-in-closure');
    }

    public function testTriesInSeparateMethodsPassStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'sibling-tries');
    }
}
