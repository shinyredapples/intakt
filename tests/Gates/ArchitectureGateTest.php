<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\Architecture\MessageHandlerOnlyThroughContractAttributes;
use Intakt\Gates\Architecture\Rules\HandlersNameTheirBus;
use Intakt\Gates\ArchitectureGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ArchitectureGate::class)]
#[UsesClass(MessageHandlerOnlyThroughContractAttributes::class)]
#[UsesClass(HandlersNameTheirBus::class)]
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

    public function testAHandlerUsingAsMessageHandlerDirectlyFails(): void
    {
        $this->assertRejectedBy('architecture', 'handler-direct', 'use AsCommandHandler or AsQueryHandler');
    }

    public function testAHandlerUsingAsMessageHandlerThroughAnAliasFails(): void
    {
        $this->assertRejectedBy('architecture', 'handler-through-alias', 'use AsCommandHandler or AsQueryHandler');
    }

    public function testTheKernelUsingAsMessageHandlerThroughAnAliasFails(): void
    {
        $this->assertRejectedBy('architecture', 'handler-in-kernel', 'use AsCommandHandler or AsQueryHandler');
    }

    public function testAHandlerUsingAsMessageHandlerInLowercaseFails(): void
    {
        $this->assertRejectedBy('architecture', 'handler-lowercase', 'use AsCommandHandler or AsQueryHandler');
    }

    public function testThePlatformContractMayUseAsMessageHandler(): void
    {
        $this->assertAcceptedBy('architecture', 'handler-contract');
    }

    public function testAClassWhoseFolderDoesNotMatchItsNamespaceFails(): void
    {
        $this->assertRejectedBy('architecture', 'class-in-wrong-folder', 'is not in src/Core/Order/Domain/Placed.php');
    }
}
