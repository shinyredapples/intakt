<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CodingStandardsGate;
use Intakt\Gates\PhpCsFixer\PlainFirstClassCallableFixer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(PlainFirstClassCallableFixer::class)]
#[UsesClass(CodingStandardsGate::class)]
final class PlainFirstClassCallableFixerTest extends SandboxTestCase
{
    public function testAFullyQualifiedFirstClassCallableFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'qualified-first-class-callable', 'src/Report.php: Intakt/plain_first_class_callable');
    }

    public function testAPlainFirstClassCallablePassesTheCodingStandards(): void
    {
        $this->assertAcceptedBy('cs', 'plain-first-class-callable');
    }

    public function testANamespacedFirstClassCallableIsLeftUnchangedByTheCodingStandards(): void
    {
        $this->assertAcceptedBy('cs', 'namespaced-first-class-callable');
    }
}
