<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ClassNames;
use Intakt\Gates\PhpStan\RawPromptMessageRule;
use Intakt\Gates\PhpStan\RolePromptCarriers;
use Intakt\Gates\PhpStan\RunTimeTraitConstructionCollector;
use Intakt\Gates\PhpStan\UnusedTraitCarrierRule;
use Intakt\Gates\PhpStan\UsedTraitCollector;
use Intakt\Gates\StaticAnalysisGate;
use Override;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<UnusedTraitCarrierRule>
 */
#[CoversClass(UnusedTraitCarrierRule::class)]
#[CoversClass(RunTimeTraitConstructionCollector::class)]
#[CoversClass(UsedTraitCollector::class)]
#[UsesClass(RolePromptCarriers::class)]
#[UsesClass(RawPromptMessageRule::class)]
#[UsesClass(ClassNames::class)]
final class UnusedTraitCarrierRuleTest extends PhpStanRuleTestCase
{
    private const int TRAIT_CASE_LINE = 14;
    private const string BUILDER = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal class Builder\n{\n    use Builds;\n}\n";
    private const string NESTED_TRAIT = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\ntrait BuildsMore\n{\n    use Builds;\n}\n";

    private string|false $changedFilesOnly = false;

    /**
     * @return iterable<string, array{string}>
     */
    public static function runTimeClasses(): iterable
    {
        yield 'a class in a variable' => ['new $text(\'x\', $text)'];
        yield 'a typed class string' => ['new $role(\'x\', $text)'];
        yield 'a class string it assigns itself' => ['new ($text = \ArrayObject::class)()'];
        yield 'the using class' => ['new self(\'x\', $text)'];
        yield 'the using class at run time' => ['new static(\'x\', $text)'];
        yield 'the parent of the using class' => ['new parent()'];
    }

    #[DataProvider('runTimeClasses')]
    public function testATraitNoClassUsesMayNotBuildAClassNamedOnlyAtRunTime(string $expression): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding($expression));

        self::assertSame('src/Sample/Builds.php:'.self::TRAIT_CASE_LINE.' intakt.reviewerRole A trait no class uses builds an object whose class is known only in a class that uses it: a trait body is outside ConfiguredPanels, SampleProject, ReviewRound, ReviewWithReviewerHandler and SymfonyAiVendor, so it may not build a ReviewerRole, RoundReviewer or ReviewerConversation; name the class it builds, or build the object in a class.', $this->ruleFindingsIn($sandbox));
    }

    public function testATraitUsedOnlyByATraitNoClassUsesIsJudgedWhereItIsDeclared(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding('new $text(\'x\', $text)'));
        $sandbox->write('src/Sample/BuildsMore.php', self::NESTED_TRAIT);

        self::assertStringStartsWith('src/Sample/Builds.php:'.self::TRAIT_CASE_LINE.' intakt.reviewerRole A trait no class uses', $this->ruleFindingsIn($sandbox));
    }

    public function testATraitAClassUsesIsLeftToTheMethodsTypes(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding('new $text(\'x\', $text)'));
        $sandbox->write('src/Sample/Builder.php', self::BUILDER);

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testATraitAClassUsesThroughAnotherTraitIsLeftToTheMethodsTypes(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding('new $text(\'x\', $text)'));
        $sandbox->write('src/Sample/BuildsMore.php', self::NESTED_TRAIT);
        $sandbox->write('src/Sample/Builder.php', str_replace('use Builds;', 'use BuildsMore;', self::BUILDER));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testATraitThatNamesItsClassesPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding('new \Intakt\Core\Review\Contract\ReviewerRole(\'x\', $text)'));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testATraitUnderTestsIsNotJudged(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/Sample/Builds.php', $this->traitBuilding('new $text(\'x\', $text)'));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testAnUnusedTraitIsNotJudgedInARunOfChangedFilesOnly(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding('new $text(\'x\', $text)'));
        putenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE.'=1');

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function anonymousClassesBuildingThemselves(): iterable
    {
        yield 'self' => ['new self()'];
        yield 'static' => ['new static()'];
        yield 'parent' => ['new parent()'];
        yield 'self::class' => ['new (self::class)()'];
        yield 'static::class' => ['new (static::class)()'];
        yield 'parent::class' => ['new (parent::class)()'];
        yield 'self::class in any case' => ['new (SELF::CLASS)()'];
    }

    public function testATraitNoClassUsesMayNotBuildItsUsingClassFromAClassConstant(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding('new (static::class)(\'x\', $text)'));

        self::assertStringStartsWith('src/Sample/Builds.php:14 intakt.reviewerRole A trait no class uses', $this->ruleFindingsIn($sandbox));
    }

    public function testAnAnonymousClassInATraitNoClassUsesMayNotBuildAClassFromAnotherConstant(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitWithAnonymousClassBuilding('new (self::ROLE)(\'x\', $text)'));

        self::assertStringStartsWith('src/Sample/Builds.php:17 intakt.reviewerRole A trait no class uses', $this->ruleFindingsIn($sandbox));
    }

    #[DataProvider('anonymousClassesBuildingThemselves')]
    public function testAnAnonymousClassInATraitNoClassUsesMayBuildItself(string $expression): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitWithAnonymousClassBuilding($expression));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testAnAnonymousClassInATraitNoClassUsesMayNotBuildAClassNamedOnlyAtRunTime(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitWithAnonymousClassBuilding('new $text(\'x\', $text)'));

        self::assertStringStartsWith('src/Sample/Builds.php:17 intakt.reviewerRole A trait no class uses', $this->ruleFindingsIn($sandbox));
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

    protected function ruleFor(string $root): UnusedTraitCarrierRule
    {
        return new UnusedTraitCarrierRule($root);
    }

    /**
     * @return array<Collector<Node, mixed>>
     */
    #[Override]
    protected function getCollectors(): array
    {
        return [new RunTimeTraitConstructionCollector(), new UsedTraitCollector()];
    }

    private function traitWithAnonymousClassBuilding(string $expression): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\ntrait Builds\n{\n    public function factory(): object\n    {\n        return new class () extends \\ArrayObject {\n            /** @return object */\n            public function fresh(string \$text): mixed\n            {\n                \$text = \$text;\n\n                return {$expression};\n            }\n        };\n    }\n}\n";
    }

    private function traitBuilding(string $expression): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nuse Intakt\\Core\\Review\\Domain\\RoundReviewer;\n\ntrait Builds\n{\n    /** @param class-string<\\Intakt\\Core\\Review\\Contract\\ReviewerRole> \$role */\n    public function build(string \$text, string \$role): mixed\n    {\n        return {$expression};\n    }\n}\n";
    }
}
