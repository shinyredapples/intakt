<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NoMagicMethodRule;
use Intakt\Gates\PhpStan\RepositoryCode;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<NoMagicMethodRule>
 */
#[CoversClass(NoMagicMethodRule::class)]
#[UsesClass(RepositoryCode::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoMagicMethodRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): NoMagicMethodRule
    {
        return new NoMagicMethodRule($root);
    }

    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'AMagicMethodWrittenInMixedCaseFailsStaticAnalysis' => ['magic-method-mixed-case', 'src/Proxy.php:12 intakt.noMagicMethod Method Intakt\Sample\Proxy::__Call() is a magic method: no magic methods'],
            'AFinalReadonlyServiceWithAConstructorAndInvokePassesStaticAnalysis' => ['construct-and-invoke'],
        ], 'src/');
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function magicMethods(): iterable
    {
        yield '__get' => ['src/Bag.php:12 intakt.noMagicMethod Method Intakt\Sample\Bag::__get() is a magic method'];
        yield '__set' => ['src/Bag.php:17 intakt.noMagicMethod Method Intakt\Sample\Bag::__set() is a magic method'];
        yield '__isset' => ['src/Bag.php:22 intakt.noMagicMethod Method Intakt\Sample\Bag::__isset() is a magic method'];
        yield '__unset' => ['src/Bag.php:27 intakt.noMagicMethod Method Intakt\Sample\Bag::__unset() is a magic method'];
        yield '__call' => ['src/Bag.php:35 intakt.noMagicMethod Method Intakt\Sample\Bag::__call() is a magic method'];
        yield '__callStatic' => ['src/Bag.php:43 intakt.noMagicMethod Method Intakt\Sample\Bag::__callStatic() is a magic method'];
    }

    #[DataProvider('magicMethods')]
    public function testAClassWithAMagicMethodFailsStaticAnalysis(string $expectedFinding): void
    {
        $this->assertRuleRejects('magic-property-bag', $expectedFinding);
    }

    public function testTheFindingSaysHowToComply(): void
    {
        $this->assertRuleRejects('magic-property-bag', 'declare the property or the method, or give the class a named method for the lookup.');
    }

    public function testAMagicMethodWrittenInMixedCaseFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('magic-method-mixed-case', 'src/Proxy.php:12 intakt.noMagicMethod Method Intakt\Sample\Proxy::__Call() is a magic method');
    }

    public function testAFinalReadonlyServiceWithAConstructorAndInvokePassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('construct-and-invoke');
    }

    public function testAStringableValueObjectPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('stringable-value-object');
    }

    public function testAMagicMethodOutsideTheRepositoryCodeFoldersPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('magic-method-migration');
    }
}
