<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ContainerDumpingGate;
use Intakt\Gates\PhpStan\ContainerUnknownParameterRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPStan\Symfony\ParameterMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<ContainerUnknownParameterRule>
 */
#[CoversClass(ContainerUnknownParameterRule::class)]
#[UsesClass(ContainerDumpingGate::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class ContainerUnknownParameterRuleTest extends PhpStanRuleTestCase
{
    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRunOnTheFixtureKernel([
            'ReadingAMisspelledParameterNameFailsStaticAnalysis' => ['misspelled-parameter-name', 'src/Settings.php:17 intakt.containerUnknownParameter Parameter "kernal.debug" is not defined in the container'],
            'ReadingADefinedParameterNamePassesStaticAnalysis' => ['typed-container-parameter'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    protected function ruleFor(string $root): ContainerUnknownParameterRule
    {
        return new ContainerUnknownParameterRule(self::createStub(ParameterMap::class));
    }
}
