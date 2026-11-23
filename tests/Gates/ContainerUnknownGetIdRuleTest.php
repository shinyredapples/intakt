<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ContainerDumpingGate;
use Intakt\Gates\PhpStan\AskedServiceIds;
use Intakt\Gates\PhpStan\ContainerUnknownGetIdRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'GettingEitherAKnownOrAMisspelledServiceIdFailsStaticAnalysis' => ['misspelled-get-service-id-in-union', 'src/Lookup.php:19 symfonyContainer.serviceNotFound Service "kernal" is not registered in the container'],
            'GettingEitherOfTwoRegisteredServiceIdsPassesStaticAnalysis' => ['registered-get-service-ids-in-union'],
            'GettingEitherAKnownOrAMisspelledServiceIdNamedAfterTheInvalidBehaviorFailsStaticAnalysis' => ['misspelled-get-service-id-in-union-named-last', 'src/Lookup.php:19 symfonyContainer.serviceNotFound Service "kernal" is not registered in the container'],
            'GettingEitherOfTwoRegisteredServiceIdsNamedAfterTheInvalidBehaviorPassesStaticAnalysis' => ['registered-get-service-ids-in-union-named-last'],
            'GettingEitherAKnownOrAPrivateServiceIdFailsStaticAnalysis' => ['private-get-service-id-in-union', 'src/Lookup.php:20 symfonyContainer.privateService Service "Intakt\Core\Review\Infrastructure\ReviewerPrompt" is private'],
            'GettingEitherAKnownOrAPrivateServiceIdFromTheTestContainerPassesStaticAnalysis' => ['test-container-get-private-service-id-in-union'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
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

    protected function ruleFor(string $root): ContainerUnknownGetIdRule
    {
        return new ContainerUnknownGetIdRule(new NoServices(), new AskedServiceIds());
    }
}
