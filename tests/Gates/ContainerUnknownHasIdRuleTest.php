<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ContainerDumpingGate;
use Intakt\Gates\PhpStan\AskedServiceIds;
use Intakt\Gates\PhpStan\ContainerUnknownHasIdRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'AskingTheContainerForAMisspelledServiceIdFailsStaticAnalysis' => ['misspelled-has-service-id', 'src/Lookup.php:17 intakt.containerUnknownHasId Service "kernal" is not registered in the container'],
            'AskingTheContainerForARegisteredServiceIdPassesStaticAnalysis' => ['registered-has-service-id'],
            'AskingTheContainerForEitherAKnownOrAMisspelledServiceIdFailsStaticAnalysis' => ['misspelled-has-service-id-in-union', 'src/Lookup.php:19 intakt.containerUnknownHasId Service "kernal" is not registered in the container'],
            'AskingTheContainerForEitherOfTwoRegisteredServiceIdsPassesStaticAnalysis' => ['registered-has-service-ids-in-union'],
            'ALocatorTypedAsThePsrContainerIsCheckedAsTheApplicationContainer' => ['psr-typed-locator-has-local-key', 'src/Platforms.php:17 intakt.containerUnknownHasId Service "local" is not registered in the container'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testAskingAServiceLocatorForOneOfItsOwnKeysIsAllowed(): void
    {
        $this->assertRuleAccepts('locator-has-local-key');
    }

    public function testAskingTheContainerBagForAParameterIsAllowed(): void
    {
        $this->assertRuleAccepts('container-bag-has-parameter');
    }

    protected function ruleFor(string $root): ContainerUnknownHasIdRule
    {
        return new ContainerUnknownHasIdRule(new NoServices(), new AskedServiceIds());
    }
}
