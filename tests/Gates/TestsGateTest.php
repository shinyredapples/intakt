<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TestsGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TestsGate::class)]
final class TestsGateTest extends SandboxTestCase
{
    public function testPassingTestsPassTheGate(): void
    {
        $this->assertAcceptedBy('tests', 'tests-clean');
    }

    public function testAMockWithoutExpectationsFailsTheGate(): void
    {
        $this->assertRejectedBy('tests', 'tests-mock-without-expectations', 'No expectations were configured for the mock object');
    }
}
