<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ContainerDumpingGate;
use Intakt\Gates\PhpStan\ContainerUnknownParameterRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPStan\Symfony\ParameterMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<ContainerUnknownParameterRule>
 */
#[CoversClass(ContainerUnknownParameterRule::class)]
#[UsesClass(ContainerDumpingGate::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class ContainerUnknownParameterRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): ContainerUnknownParameterRule
    {
        return new ContainerUnknownParameterRule(self::createStub(ParameterMap::class));
    }

    public function testReadingAMisspelledParameterNameFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'misspelled-parameter-name', 'src/Settings.php:17 intakt.containerUnknownParameter Parameter "kernal.debug" is not defined in the container');
    }

    public function testReadingADefinedParameterNamePassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'typed-container-parameter');
    }
}
