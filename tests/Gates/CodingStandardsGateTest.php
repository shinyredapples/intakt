<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ArchitectureGate;
use Intakt\Gates\CodingStandardsGate;
use Intakt\Gates\CommentsGate;
use Intakt\Gates\RectorGate;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(CodingStandardsGate::class)]
#[UsesClass(StaticAnalysisGate::class)]
#[UsesClass(RectorGate::class)]
#[UsesClass(CommentsGate::class)]
#[UsesClass(ArchitectureGate::class)]
final class CodingStandardsGateTest extends SandboxTestCase
{
    public function testACompliantClassPassesEveryCodeGate(): void
    {
        foreach (['cs', 'phpstan', 'rector', 'comments', 'architecture'] as $gate) {
            $this->assertAcceptedBy($gate, 'clean');
        }
    }

    public function testAFullyQualifiedClassNameInCodeFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'fully-qualified-name-in-code', 'src/Report.php: global_namespace_import');
    }

    public function testAFullyQualifiedClassNameInAnAttributeFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'fully-qualified-name-in-attribute', 'src/Marker.php: global_namespace_import');
    }

    public function testAFullyQualifiedClassNameInADocblockFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'fully-qualified-name-in-docblock', 'src/Report.php: fully_qualified_strict_types');
    }

    public function testAFullyQualifiedFunctionOrConstantFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'qualified-function-and-constant', 'src/Report.php: native_constant_invocation, native_function_invocation');
    }

    public function testAFunctionOrConstantImportIsRemovedByTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'imported-function-and-constant', 'src/Report.php: global_namespace_import');
    }

    public function testASuperfluousDocblockFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'superfluous-docblock', 'no_superfluous_phpdoc_tags');
    }
}
