<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\AncestorDeclarations;
use Intakt\Gates\PhpStan\NoPublicSetterRule;
use Intakt\Gates\PhpStan\RepositoryCode;
use Intakt\Gates\PhpStan\SetterMethod;
use Intakt\Gates\PhpStan\VendorSources;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<NoPublicSetterRule>
 */
#[CoversClass(NoPublicSetterRule::class)]
#[CoversClass(SetterMethod::class)]
#[CoversClass(VendorSources::class)]
#[UsesClass(AncestorDeclarations::class)]
#[UsesClass(RepositoryCode::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoPublicSetterRuleTest extends PhpStanRuleTestCase
{
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

    public function testASetterWithAnUppercaseSetPrefixFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('mixed-case-setter', 'src/Upper.php:11 intakt.noPublicSetter Method Intakt\Sample\Upper::SetName() is a public setter');
    }

    public function testASetterWithAnAllCapitalsSetPrefixFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('mixed-case-setter', 'src/Upper.php:16 intakt.noPublicSetter Method Intakt\Sample\Upper::SETLabel() is a public setter');
    }

    public function testAMethodWhoseCapitalizedNameOnlyStartsWithTheLettersSetIsNotReported(): void
    {
        self::assertStringNotContainsString('Settings()', $this->ruleFindingsOf('mixed-case-setter'));
    }

    public function testASetterOverridingAParentInTheMigrationsFolderFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('migration-parent-setter', 'src/LocalChild.php:12 intakt.noPublicSetter Method Intakt\Sample\LocalChild::setName() is a public setter');
    }

    public function testASetterDeclaredInATraitIsLeftToTheTraitSetterRule(): void
    {
        $this->assertRuleAccepts('used-trait-setter');
    }

    public function testATraitMethodAliasedToAPublicSetterFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('aliased-trait-setter', 'src/Person.php:10 intakt.noPublicSetter Method Intakt\Sample\Person::setName() is a public setter');
    }

    public function testATraitMethodAliasedToAPrivateSetterPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('trait-with-named-methods');
    }

    public function testAPublicSetterAliasMaskedByAProtectedMethodOfTheClassPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('protected-method-masks-setter-alias');
    }

    public function testAPublicSetterMaskingAPrivateAliasIsReportedOnceWhereTheClassDeclaresIt(): void
    {
        $findings = $this->ruleFindingsOf('public-method-masks-setter-alias');

        self::assertStringStartsWith('src/Person.php:13 intakt.noPublicSetter Method Intakt\Sample\Person::setName() is a public setter', $findings);
        self::assertCount(1, explode("\n", $findings));
    }

    public function testASetterAliasInATraitNoClassUsesFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('unused-trait-alias-setter', 'src/WrappedSetter.php:10 intakt.noPublicSetter Method Intakt\Sample\WrappedSetter::setLabel() is a public setter');
    }

    public function testASetterAliasInAUsedTraitIsReportedOnceWhereTheTraitDeclaresIt(): void
    {
        $findings = $this->ruleFindingsOf('used-trait-alias-setter');

        self::assertStringStartsWith('src/WrappedSetter.php:10 intakt.noPublicSetter Method Intakt\Sample\WrappedSetter::setLabel() is a public setter', $findings);
        self::assertCount(1, explode("\n", $findings));
    }

    public function testAPrivateSetterAliasInATraitPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('private-trait-alias-setter');
    }

    public function testKeepingAnAlreadyPublicTraitSetterPublicAddsNoClassFinding(): void
    {
        $this->assertRuleAccepts('redundant-public-trait-setter-alias');
    }

    public function testMakingAProtectedTraitSetterPublicByAnAliasFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('widened-trait-setter-alias', 'src/Book.php:10 intakt.noPublicSetter Method Intakt\Sample\Book::setTitle() is a public setter');
    }

    public function testMakingAProtectedTraitSetterPublicByALowercaseAliasFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('lowercase-widened-trait-setter-alias', 'src/Book.php:10 intakt.noPublicSetter Method Intakt\Sample\Book::setTitle() is a public setter');
    }

    public function testWideningAnExcludedTraitSetterPassesWhenTheClassExposesOnlyALowercaseName(): void
    {
        $this->assertRuleAccepts('excluded-trait-setter-alias');
    }

    protected function ruleFor(string $root): NoPublicSetterRule
    {
        return new NoPublicSetterRule($root, self::createReflectionProvider());
    }
}
