<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CodingStandardsGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CodingStandardsGate::class)]
final class MethodOrderCodingStandardsTest extends SandboxTestCase
{
    public function testAPrivateMethodAboveAPublicOneFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'private-method-above-public', 'src/Report.php: ordered_class_elements');
    }

    public function testAConstructorBelowAMethodFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'constructor-below-a-method', 'src/Report.php: ordered_class_elements');
    }

    public function testConstructorThenPublicThenProtectedThenPrivateMethodsPassTheCodingStandards(): void
    {
        $this->assertAcceptedBy('cs', 'methods-in-visibility-order');
    }
}
