<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\AbstractTraitSetterUseCollector;
use Intakt\Gates\PhpStan\AncestorDeclarations;
use Intakt\Gates\PhpStan\NoPublicTraitSetterRule;
use Intakt\Gates\PhpStan\RepositoryCode;
use Intakt\Gates\PhpStan\SetterMethod;
use Intakt\Gates\PhpStan\TraitSetterDeclarationCollector;
use Intakt\Gates\PhpStan\TraitSetterUseCollector;
use Intakt\Gates\PhpStan\VendorSources;
use Intakt\Gates\StaticAnalysisGate;
use Override;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<NoPublicTraitSetterRule>
 */
#[CoversClass(NoPublicTraitSetterRule::class)]
#[CoversClass(TraitSetterDeclarationCollector::class)]
#[CoversClass(TraitSetterUseCollector::class)]
#[CoversClass(AbstractTraitSetterUseCollector::class)]
#[CoversClass(AncestorDeclarations::class)]
#[UsesClass(RepositoryCode::class)]
#[UsesClass(SetterMethod::class)]
#[UsesClass(VendorSources::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoPublicTraitSetterRuleTest extends PhpStanRuleTestCase
{
    private string|false $changedFilesOnly = false;

    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'ASetterOfATraitNoClassUsesFailsStaticAnalysis' => ['unused-trait-setter', 'src/Titled.php:9 intakt.noPublicSetter Method Intakt\Sample\Titled::setTitle() is a public setter: no public setters'],
            'ATraitSetterOverridingAVendorParentPassesWithoutTheOverrideAttribute' => ['vendor-parent-plain-trait-setter'],
        ], 'src/');
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testASetterOfATraitNoClassUsesFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('unused-trait-setter', 'src/Titled.php:9 intakt.noPublicSetter Method Intakt\Sample\Titled::setTitle() is a public setter');
    }

    public function testTheFindingSaysHowToComply(): void
    {
        $this->assertRuleRejects('unused-trait-setter', 'pass the value to the constructor, or return a new instance from a with*() method (clone() with overrides).');
    }

    public function testASetterOfAUsedTraitIsReportedOnceWhereTheTraitDeclaresIt(): void
    {
        $findings = $this->ruleFindingsOf('used-trait-setter');

        self::assertStringStartsWith('src/Titled.php:9 intakt.noPublicSetter Method Intakt\Sample\Titled::setTitle() is a public setter', $findings);
        self::assertCount(1, explode("\n", $findings));
    }

    public function testASetterOfAnUnusedTraitFailsStaticAnalysisWithOrWithoutTheOverrideAttribute(): void
    {
        $findings = explode("\n", $this->ruleFindingsOf('unused-override-trait-setter'));

        self::assertCount(2, $findings);
        self::assertStringStartsWith('src/MarkedTrait.php:11 intakt.noPublicSetter Method Intakt\Sample\MarkedTrait::setTitle() is a public setter', $findings[0]);
        self::assertStringStartsWith('src/PlainTrait.php:9 intakt.noPublicSetter Method Intakt\Sample\PlainTrait::setTitle() is a public setter', $findings[1]);
    }

    public function testATraitSetterOverridingAVendorParentPassesWithTheOverrideAttribute(): void
    {
        $this->assertRuleAccepts('vendor-parent-trait-setter');
    }

    public function testATraitSetterOverridingAVendorParentPassesWithoutTheOverrideAttribute(): void
    {
        $this->assertRuleAccepts('vendor-parent-plain-trait-setter');
    }

    public function testAnAbstractTraitSetterThatAVendorParentImplementsPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('vendor-parent-abstract-trait-setter');
    }

    public function testAnAbstractTraitSetterNoVendorParentDeclaresFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('used-abstract-trait-setter', 'src/Titled.php:9 intakt.noPublicSetter Method Intakt\Sample\Titled::setTitle() is a public setter');
    }

    public function testAChangedFilesRunThatAnalyzesOnlyTheTraitReportsNoTraitSetter(): void
    {
        putenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE.'=1');

        self::assertSame('', $this->ruleFindingsIn($this->traitWithoutItsUsingClass()));
    }

    public function testARunThatAnalyzesOnlyTheTraitWithoutTheSignalReportsItsSetter(): void
    {
        self::assertStringStartsWith('src/SharedSetup.php:11 intakt.noPublicSetter Method Intakt\Sample\SharedSetup::setUpBeforeClass() is a public setter', $this->ruleFindingsIn($this->traitWithoutItsUsingClass()));
    }

    public function testASignalOtherThanOneKeepsTheTraitSetterCheckOn(): void
    {
        putenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE.'=true');

        self::assertStringStartsWith('src/SharedSetup.php:11 intakt.noPublicSetter', $this->ruleFindingsIn($this->traitWithoutItsUsingClass()));
    }

    public function testATraitSetterIsReportedOnceWhenOneOfItsUsingClassesHasNoVendorParentDeclaringIt(): void
    {
        $findings = $this->ruleFindingsOf('partly-vendor-trait-setter');

        self::assertStringStartsWith('src/SharedSetup.php:9 intakt.noPublicSetter Method Intakt\Sample\SharedSetup::setUpBeforeClass() is a public setter', $findings);
        self::assertCount(1, explode("\n", $findings));
    }

    public function testATraitSetterOverridingARepositoryParentFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('repository-parent-trait-setter', 'src/Renaming.php:11 intakt.noPublicSetter Method Intakt\Sample\Renaming::setName() is a public setter');
    }

    public function testAPrivateTraitSetterPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('private-trait-setter');
    }

    public function testATraitSetterOutsideTheRepositoryCodeFoldersPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('trait-setter-migration');
    }

    #[Override]
    protected function setUp(): void
    {
        $this->changedFilesOnly = getenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE);
        putenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE);
        parent::setUp();
    }

    #[Override]
    protected function tearDown(): void
    {
        putenv(false === $this->changedFilesOnly ? StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE : StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE.'='.$this->changedFilesOnly);
        parent::tearDown();
    }

    protected function ruleFor(string $root): NoPublicTraitSetterRule
    {
        return new NoPublicTraitSetterRule($root);
    }

    /**
     * @return array<Collector<Node, mixed>>
     */
    #[Override]
    protected function getCollectors(): array
    {
        return [new TraitSetterDeclarationCollector(), new TraitSetterUseCollector(), new AbstractTraitSetterUseCollector()];
    }

    // because a changed-files run where only the trait changed does not analyze the class that uses it
    private function traitWithoutItsUsingClass(): Sandbox
    {
        $sandbox = $this->sandboxWith('vendor-parent-trait-setter');
        unlink($sandbox->root.'/src/SampleTest.php');

        return $sandbox;
    }
}
