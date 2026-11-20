<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NoPublicSetterRule;
use Intakt\Gates\PhpStan\RepositoryCode;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<NoPublicSetterRule>
 */
#[CoversClass(NoPublicSetterRule::class)]
#[UsesClass(RepositoryCode::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoPublicSetterRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): NoPublicSetterRule
    {
        return new NoPublicSetterRule($root);
    }

    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'AFinalReadonlyServiceWithAPublicSetterFailsStaticAnalysis' => ['public-setter-service', 'src/Mailer.php:13 intakt.noPublicSetter Method Intakt\Sample\Mailer::setSender() is a public setter: no public setters'],
            'AClassImplementingAVendorInterfaceSetterPassesStaticAnalysis' => ['vendor-interface-setter'],
        ], 'src/');
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testAFinalReadonlyServiceWithAPublicSetterFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('public-setter-service', 'src/Mailer.php:13 intakt.noPublicSetter Method Intakt\Sample\Mailer::setSender() is a public setter: no public setters');
    }

    public function testTheFindingSaysHowToComply(): void
    {
        $this->assertRuleRejects('public-setter-service', 'pass the value to the constructor, or return a new instance from a with*() method (clone() with overrides).');
    }

    public function testASetterOfASymfonyWorkflowMarkingStoreInterfacePassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('vendor-interface-setter');
    }

    public function testASetterOverridingAVendorParentClassPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('vendor-parent-setter');
    }

    public function testASetterRequiredByAnInterfaceOfTheRepositoryFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('repository-interface-setter', 'src/Logger.php:11 intakt.noPublicSetter Method Intakt\Sample\Logger::setLevel() is a public setter');
    }

    public function testASetterDeclaredByAnInterfaceOfTheRepositoryFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('repository-interface-setter', 'src/Configurable.php:9 intakt.noPublicSetter Method Intakt\Sample\Configurable::setLevel() is a public setter');
    }

    public function testAPrivateSetterPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('private-setter');
    }

    public function testAProtectedSetterPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('protected-setter');
    }

    public function testAPublicMethodWhoseNameOnlyStartsWithTheLettersSetPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('setter-like-words');
    }

    public function testASnakeCaseSetterInATestClassFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('public-setter-test-class', 'tests/MailerTest.php:11 intakt.noPublicSetter Method Intakt\Sample\MailerTest::set_sender() is a public setter');
    }

    public function testASetterOutsideTheRepositoryCodeFoldersPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('public-setter-migration');
    }
}
