<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ContainerDumpingGate;
use Intakt\Gates\PhpStan\ContainerUnknownHasIdRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ContainerUnknownHasIdRule::class)]
#[UsesClass(ContainerDumpingGate::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class ContainerUnknownHasIdRuleTest extends SandboxTestCase
{
    public function testAskingTheContainerForAMisspelledServiceIdFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'misspelled-has-service-id', 'src/Lookup.php:17 intakt.containerUnknownHasId Service "kernal" is not registered in the container');
    }

    public function testAskingTheContainerForARegisteredServiceIdPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'registered-has-service-id');
    }

    public function testAskingAServiceLocatorForOneOfItsOwnKeysPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'locator-has-local-key');
    }
}
