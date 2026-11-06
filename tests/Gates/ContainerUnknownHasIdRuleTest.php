<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ContainerDumpingGate;
use Intakt\Gates\PhpStan\ContainerUnknownHasIdRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPStan\Symfony\DefaultServiceMap;
use PHPStan\Symfony\Service;
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
        return new ContainerUnknownHasIdRule(new DefaultServiceMap(['kernel' => new Service('kernel', null, public: true, synthetic: true, alias: null)]));
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
