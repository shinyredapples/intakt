<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\RectorGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RectorGate::class)]
final class RectorGateTest extends SandboxTestCase
{
    public function testAClassNameWrittenAsAStringFailsTheRectorDryRun(): void
    {
        $this->assertRejectedBy('rector', 'class-name-as-string', 'StringClassNameToClassConstantRector');
    }

    public function testDeadCodeFailsTheRectorDryRun(): void
    {
        $this->assertRejectedBy('rector', 'dead-code', 'RemoveUnusedVariableAssignRector');
    }
}
