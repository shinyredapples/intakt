<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ArchitectureGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ArchitectureGate::class)]
#[UsesClass(SandboxTestCase::class)]
final class ArchitectureRulesTest extends SandboxTestCase
{
    public function testAContractClassDependingOnItsUseCasesFails(): void
    {
        $this->assertRejectedBy('architecture', 'contract-uses-use-case', 'OrderSummary');
    }
}
