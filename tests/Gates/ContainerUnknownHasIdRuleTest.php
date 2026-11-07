<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ContainerDumpingGate;
use Intakt\Gates\PhpStan\AskedServiceIds;
use Intakt\Gates\PhpStan\ContainerUnknownHasIdRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<ContainerUnknownHasIdRule>
 */
#[CoversClass(ContainerUnknownHasIdRule::class)]
#[UsesClass(AskedServiceIds::class)]
#[UsesClass(ContainerDumpingGate::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class ContainerUnknownHasIdRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): ContainerUnknownHasIdRule
    {
        return new ContainerUnknownHasIdRule(new NoServices(), new AskedServiceIds());
    }

    public function testAskingTheContainerForAMisspelledServiceIdFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'misspelled-has-service-id', 'src/Lookup.php:17 intakt.containerUnknownHasId Service "kernal" is not registered in the container');
    }

    public function testAskingTheContainerForARegisteredServiceIdPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'registered-has-service-id');
    }

    public function testAskingTheContainerForEitherAKnownOrAMisspelledServiceIdFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'misspelled-has-service-id-in-union', 'src/Lookup.php:19 intakt.containerUnknownHasId Service "kernal" is not registered in the container');
    }

    public function testAskingTheContainerForEitherOfTwoRegisteredServiceIdsPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'registered-has-service-ids-in-union');
    }

    public function testAskingAServiceLocatorForOneOfItsOwnKeysIsAllowed(): void
    {
        $this->assertRuleAccepts('locator-has-local-key');
    }

    public function testAskingTheContainerBagForAParameterIsAllowed(): void
    {
        $this->assertRuleAccepts('container-bag-has-parameter');
    }
}
