<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NoStaticPropertyRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<NoStaticPropertyRule>
 */
#[CoversClass(NoStaticPropertyRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoStaticPropertyRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): NoStaticPropertyRule
    {
        return new NoStaticPropertyRule();
    }

    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'InstancePropertiesAndConstantsPassStaticAnalysis' => ['instance-properties'],
            'StaticMethodsAndClosuresPassStaticAnalysis' => ['static-method'],
            'APrivateStaticPropertyFailsStaticAnalysis' => ['static-property', 'src/Counter.php:9 intakt.noStaticProperty'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testAStaticPropertyInATraitFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('trait-static-property', 'intakt.noStaticProperty');
    }

    public function testAStaticPropertyInATraitNoClassUsesFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('unused-trait-static-property', 'src/Lonely.php:9 intakt.noStaticProperty');
    }

    public function testAStaticPropertyInAnAnonymousClassFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('anonymous-class-static-property', 'src/Maker.php:12 intakt.noStaticProperty');
    }
}
