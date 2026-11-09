<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ContainerDumpingGate;
use Intakt\Gates\PhpStan\AskedServiceIds;
use Intakt\Gates\PhpStan\ContainerUnknownGetIdRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<ContainerUnknownGetIdRule>
 */
#[CoversClass(ContainerUnknownGetIdRule::class)]
#[CoversClass(AskedServiceIds::class)]
#[UsesClass(ContainerDumpingGate::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class ContainerUnknownGetIdRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): ContainerUnknownGetIdRule
    {
        return new ContainerUnknownGetIdRule(new NoServices(), new AskedServiceIds());
    }

    public function testGettingEitherAKnownOrAMisspelledServiceIdFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'misspelled-get-service-id-in-union', 'src/Lookup.php:19 symfonyContainer.serviceNotFound Service "kernal" is not registered in the container');
    }

    public function testGettingEitherOfTwoRegisteredServiceIdsPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'registered-get-service-ids-in-union');
    }

    public function testGettingEitherAKnownOrAMisspelledServiceIdNamedAfterTheInvalidBehaviorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'misspelled-get-service-id-in-union-named-last', 'src/Lookup.php:19 symfonyContainer.serviceNotFound Service "kernal" is not registered in the container');
    }

    public function testGettingEitherOfTwoRegisteredServiceIdsNamedAfterTheInvalidBehaviorPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'registered-get-service-ids-in-union-named-last');
    }

    public function testGettingEitherAKnownOrAPrivateServiceIdFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'private-get-service-id-in-union', 'src/Lookup.php:20 symfonyContainer.privateService Service "Intakt\Core\Review\Infrastructure\ReviewerPrompt" is private');
    }

    public function testGettingEitherAKnownOrAPrivateServiceIdFromTheTestContainerPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'test-container-get-private-service-id-in-union');
    }

    public function testGettingASingleMisspelledServiceIdIsLeftToPhpstanSymfony(): void
    {
        $this->assertRuleAccepts('misspelled-service-id');
    }

    public function testGettingEitherOfAServiceLocatorsOwnKeysIsAllowed(): void
    {
        $this->assertRuleAccepts('locator-get-local-keys-in-union');
    }

    public function testGettingEitherOfTwoParametersFromTheContainerBagIsAllowed(): void
    {
        $this->assertRuleAccepts('container-bag-get-parameters-in-union');
    }
}
