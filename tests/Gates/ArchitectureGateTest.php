<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ArchitectureGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ArchitectureGate::class)]
final class ArchitectureGateTest extends SandboxTestCase
{
    public function testModulesThatKeepToTheirLayersPass(): void
    {
        $this->assertAcceptedBy('architecture', 'layout-clean');
    }

    public function testAModuleUsingAnotherModulesDomainFails(): void
    {
        $this->assertRejectedBy('architecture', 'module-reaches-into-another-module', 'Intakt\Core\Cart\Domain\Cart');
    }

    public function testAClassDirectlyInTheUseCaseFolderFails(): void
    {
        $this->assertRejectedBy('architecture', 'class-outside-use-case-folder', 'UseCase/<Name>');
    }

    public function testADomainClassDependingOnItsUseCasesFails(): void
    {
        $this->assertRejectedBy('architecture', 'domain-uses-use-case', 'PlaceOrderHandler');
    }

    public function testAClassWhoseFolderDoesNotMatchItsNamespaceFails(): void
    {
        $this->assertRejectedBy('architecture', 'class-in-wrong-folder', 'is not in src/Core/Order/Domain/Placed.php');
    }
}
