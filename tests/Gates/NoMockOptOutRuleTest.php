<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NoMockOptOutRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(NoMockOptOutRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoMockOptOutRuleTest extends SandboxTestCase
{
    public function testOptingOutOfMockExpectationsFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'mock-opt-out', 'intakt.mockOptOut');
    }
}
