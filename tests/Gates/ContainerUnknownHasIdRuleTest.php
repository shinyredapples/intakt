<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ContainerDumpingGate;
use Intakt\Gates\PhpStan\ContainerUnknownHasIdRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<ContainerUnknownHasIdRule>
 */
#[CoversClass(ContainerUnknownHasIdRule::class)]
#[UsesClass(ContainerDumpingGate::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class ContainerUnknownHasIdRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): ContainerUnknownHasIdRule
    {
        return new ContainerUnknownHasIdRule(new NoServices());
    }

    public function testAskingTheContainerForAMisspelledServiceIdFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'misspelled-has-service-id', 'src/Lookup.php:17 intakt.containerUnknownHasId Service "kernal" is not registered in the container');
    }

    public function testAskingTheContainerForARegisteredServiceIdPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'registered-has-service-id');
    }

    public function testAskingAServiceLocatorForOneOfItsOwnKeysIsAllowed(): void
    {
        $this->assertRuleAccepts('locator-has-local-key');
    }
}
