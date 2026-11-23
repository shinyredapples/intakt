<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NoRequiredAttributeRule;
use Intakt\Gates\PhpStan\RepositoryCode;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<NoRequiredAttributeRule>
 */
#[CoversClass(NoRequiredAttributeRule::class)]
#[UsesClass(RepositoryCode::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoRequiredAttributeRuleTest extends PhpStanRuleTestCase
{
    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'AFinalReadonlyServiceWithARequiredWitherFailsStaticAnalysis' => ['required-wither', 'src/Notifier.php:18 intakt.constructorInjectionOnly Attribute #[Required] wires a dependency after construction: constructor injection only'],
            'AFinalReadonlyServiceWithConstructorInjectionPassesStaticAnalysis' => ['constructor-injected-service'],
        ], 'src/');
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testAFinalReadonlyServiceWithARequiredWitherFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('required-wither', 'src/Notifier.php:18 intakt.constructorInjectionOnly Attribute #[Required] wires a dependency after construction');
    }

    public function testARequiredPropertyImportedUnderAnAliasInATestFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('aliased-required-property', 'tests/LoggedCase.php:12 intakt.constructorInjectionOnly Attribute #[Required] wires a dependency after construction');
    }

    public function testTheFindingSaysHowToComply(): void
    {
        $this->assertRuleRejects('required-wither', 'take the dependency as a constructor parameter.');
    }

    public function testAFinalReadonlyServiceWithConstructorInjectionPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('constructor-injected-service');
    }

    public function testAnAttributeOfAnotherClassNamedRequiredPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('own-required-attribute');
    }

    protected function ruleFor(string $root): NoRequiredAttributeRule
    {
        return new NoRequiredAttributeRule($root);
    }
}
