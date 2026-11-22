<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\DeprecatedClasses;
use Intakt\Gates\PhpStan\DeprecatedDocblockTypes;
use Intakt\Gates\PhpStan\DocblockTypeNames;
use Intakt\Gates\PhpStan\FileScopeDeprecation;
use Intakt\Gates\PhpStan\NoDeprecatedClassDocblockTypeRule;
use Intakt\Gates\PhpStan\NoDeprecatedDocblockTypeRule;
use Intakt\Gates\PhpStan\NoDeprecatedReferenceRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\Type\FileTypeMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<DeprecationRules>
 */
#[CoversClass(NoDeprecatedReferenceRule::class)]
#[CoversClass(NoDeprecatedDocblockTypeRule::class)]
#[CoversClass(NoDeprecatedClassDocblockTypeRule::class)]
#[CoversClass(DeprecatedDocblockTypes::class)]
#[CoversClass(DeprecatedClasses::class)]
#[CoversClass(FileScopeDeprecation::class)]
#[CoversClass(DocblockTypeNames::class)]
#[UsesClass(StaticAnalysisGate::class)]
#[UsesClass(DeprecationRules::class)]
final class NoDeprecatedReferenceRuleTest extends PhpStanRuleTestCase
{
    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'ImportingADeprecatedClassFailsStaticAnalysis' => ['deprecated-import', 'src/Consumer.php:7 intakt.deprecatedReference'],
            'TheNonDeprecatedReplacementPassesStaticAnalysis' => ['deprecated-replacement'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testCheckingInstanceofAgainstADeprecatedClassFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('deprecated-instanceof', 'intakt.deprecatedReference');
    }

    public function testCatchingADeprecatedExceptionFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('deprecated-catch', 'intakt.deprecatedReference');
    }

    public function testADeprecatedClassInADocblockTypeFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('deprecated-docblock', 'intakt.deprecatedReference');
    }

    public function testTheClassConstantOfADeprecatedClassFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('deprecated-class-constant', 'intakt.deprecatedReference');
    }

    public function testADeprecatedAttributeClassFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('deprecated-attribute', 'intakt.deprecatedReference');
    }

    public function testAClassWhoseFileTriggersADeprecationAtFileScopeFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('deprecated-file-scope', 'intakt.deprecatedReference');
    }

    public function testATriggerDeprecationInsideAMethodDoesNotMakeTheClassDeprecated(): void
    {
        $this->assertRuleAccepts('deprecated-trigger-in-method');
    }

    public function testADeprecatedClassInAClassLevelPropertyTagFailsStaticAnalysis(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "/**\n * @property Legacy \$item\n */\nfinal class Consumer\n{\n}\n");
    }

    public function testADeprecatedClassInAMethodTagFailsStaticAnalysis(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "/**\n * @method Legacy make()\n */\nfinal class Consumer\n{\n}\n");
    }

    public function testADeprecatedClassAsATemplateBoundFailsStaticAnalysis(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "/**\n * @template T of Legacy\n */\nfinal class Consumer\n{\n}\n");
    }

    public function testInstantiatingAClassThatDeprecatesAtFileScopeFailsStaticAnalysis(): void
    {
        $this->assertConsumerRefused('deprecated-file-scope', "final class Consumer\n{\n    public function create(): object\n    {\n        return new Legacy();\n    }\n}\n");
    }

    public function testAFileScopeTriggerInsideAConditionStillMakesTheClassDeprecated(): void
    {
        $this->assertLegacyRefused("if ('disabled' !== getenv('LEGACY_DEPRECATION')) {\n    trigger_deprecation('acme/legacy', '1.0', 'Legacy is deprecated.');\n}\n\nclass Legacy\n{\n}\n");
    }

    public function testImportingADeprecatedClassInAGroupImportFailsStaticAnalysis(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "use Intakt\\Sample\\{Legacy};\n\nfinal class Consumer\n{\n}\n");
    }

    public function testADeprecatedClassInATraitLevelDocblockFailsStaticAnalysis(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "/**\n * @property Legacy \$item\n */\ntrait Helper\n{\n}\n\nfinal class Consumer\n{\n    use Helper;\n}\n");
    }

    public function testAFileScopeTriggerWrittenInUpperCaseStillMakesTheClassDeprecated(): void
    {
        $this->assertLegacyRefused("\\TRIGGER_DEPRECATION('acme/legacy', '1.0', 'Legacy is deprecated.');\n\nclass Legacy\n{\n}\n");
    }

    public function testAFirstClassCallableOfTriggerDeprecationDoesNotMakeTheClassDeprecated(): void
    {
        $this->assertLegacyAccepted("\$warn = \\trigger_deprecation(...);\n\nclass Legacy\n{\n}\n");
    }

    public function testAFunctionInAGroupImportIsNoClassReference(): void
    {
        $findings = $this->checkConsumer('deprecated-replacement', "use Intakt\\Sample\\{function Legacy};\n\nfinal class Consumer\n{\n}\n");

        self::assertStringNotContainsString('intakt.deprecatedReference', $findings);
    }

    public function testADeprecatedClassInAUnionTheAnalyserSimplifiesFailsStaticAnalysis(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "final class Consumer\n{\n    /**\n     * @param object|Legacy \$item\n     */\n    public function take(object \$item): void\n    {\n    }\n}\n");
    }

    public function testADeprecatedClassInAClosureDocblockFailsStaticAnalysis(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "final class Consumer\n{\n    public function make(): callable\n    {\n        return /** @return Legacy */ static function (): object {\n            throw new \\LogicException();\n        };\n    }\n}\n");
    }

    public function testADeprecatedClassInAnInlineVarTagInsideAMethodFailsStaticAnalysis(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "final class Consumer\n{\n    public function take(object \$item): object\n    {\n        /** @var Legacy \$item */\n        return \$item;\n    }\n}\n");
    }

    public function testADeprecatedClassInAPromotedPropertyDocblockFailsStaticAnalysis(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "final readonly class Consumer\n{\n    public function __construct(\n        /** @var Legacy */\n        private object \$item,\n    ) {\n    }\n}\n");
    }

    public function testADeprecatedClassInAConstantTypeFailsStaticAnalysis(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "final class Consumer\n{\n    /**\n     * @param Legacy::* \$mode\n     */\n    public function take(string \$mode): void\n    {\n    }\n}\n");
    }

    public function testATriggerDeprecationInsideAFileScopeClosureDoesNotMakeTheClassDeprecated(): void
    {
        $this->assertLegacyAccepted("const WARN = static function (): void {\n    trigger_deprecation('acme/legacy', '1.0', 'Legacy is deprecated.');\n};\n\nclass Legacy\n{\n}\n");
    }

    public function testAFunctionDocblockNamingADeprecatedClassFailsStaticAnalysis(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "/**\n * @phpstan-assert Legacy \$value\n */\nfunction ensure(object \$value): void\n{\n    if (!\$value instanceof Modern) {\n        throw new \\LogicException();\n    }\n}\n\nfinal class Consumer\n{\n}\n");
    }

    // because six accepted bodies share one analysed file, a finding in it names the line of the body that caused it
    public function testTheReplacementPassesStaticAnalysisInEveryDocblockPosition(): void
    {
        $this->assertConsumerAccepted('deprecated-replacement', <<<'PHP'
            /**
             * @template T of Modern
             *
             * @property Modern $item
             *
             * @method Modern make()
             */
            final class ClassLevelTagsConsumer
            {
            }

            /**
             * @property Modern $item
             */
            trait TraitLevelHelper
            {
            }

            final class TraitLevelConsumer
            {
                use TraitLevelHelper;
            }

            final class UnionClosureConstantConsumer
            {
                /**
                 * @param object|Modern $item
                 * @param Modern::* $mode
                 */
                public function take(object $item, string $mode): callable
                {
                    return /** @return Modern */ static fn (): object => new Modern();
                }
            }

            final class ArrayShapeConsumer
            {
                /**
                 * @return array{Legacy: Modern}
                 */
                public function shape(): array
                {
                    return ['Legacy' => new Modern()];
                }
            }

            /**
             * @phpstan-assert Modern $value
             */
            function ensure(object $value): void
            {
                if (!$value instanceof Modern) {
                    throw new \LogicException();
                }
            }

            /**
             * @property Modern $item
             */
            trait UnusedTrait
            {
                /**
                 * @param Modern $item
                 */
                public function take(object $item): object
                {
                    /** @var Modern $item */
                    return $item;
                }
            }

            PHP);
    }

    public function testAnUnusedTraitCannotNameADeprecatedClassInItsDocblock(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "/**\n * @property Legacy \$item\n */\ntrait Consumer\n{\n}\n");
    }

    public function testAnUnusedTraitCannotNameADeprecatedClassInAMethodDocblock(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "trait Consumer\n{\n    /**\n     * @param Legacy \$item\n     */\n    public function take(object \$item): void\n    {\n    }\n}\n");
    }

    public function testAnUnusedTraitCannotNameADeprecatedClassInAStatementDocblockOfAMethod(): void
    {
        $this->assertConsumerRefused('deprecated-replacement', "trait Consumer\n{\n    public function take(object \$item): object\n    {\n        /** @var Legacy \$item */\n        return \$item;\n    }\n}\n");
    }

    public function testAUsedTraitDocblockIsReportedOnce(): void
    {
        $findings = $this->checkConsumer('deprecated-replacement', "/**\n * @property Legacy \$item\n */\ntrait Helper\n{\n    /**\n     * @param Legacy \$item\n     */\n    public function take(object \$item): void\n    {\n    }\n}\n\nfinal class Consumer\n{\n    use Helper;\n}\n\nfinal class OtherConsumer\n{\n    use Helper;\n}\n");

        self::assertSame(2, substr_count($findings, 'intakt.deprecatedReference'), $findings);
    }

    public function testADifferentNamespacedFunctionDoesNotDeprecateAClass(): void
    {
        $this->assertLegacyAccepted("function trigger_deprecation(): void\n{\n}\n\ntrigger_deprecation();\n\nclass Legacy\n{\n}\n");
    }

    public function testAnAliasedDeprecationCallDeprecatesAClass(): void
    {
        $this->assertLegacyRefused("use function trigger_deprecation as warn;\n\nwarn('acme/legacy', '1.0', 'Legacy is deprecated.');\n\nclass Legacy\n{\n}\n");
    }

    protected function ruleFor(string $root): DeprecationRules
    {
        $reflections = self::createReflectionProvider();
        $deprecated = new DeprecatedClasses($reflections, new FileScopeDeprecation(self::getParser(), $reflections));
        $docblocks = new DeprecatedDocblockTypes(self::getContainer()->getByType(FileTypeMapper::class), self::getContainer()->getByType(TypeNodeResolver::class), $deprecated);

        return new DeprecationRules(
            new NoDeprecatedReferenceRule($deprecated),
            new NoDeprecatedDocblockTypeRule($docblocks),
            new NoDeprecatedClassDocblockTypeRule($docblocks),
        );
    }

    private function assertLegacyRefused(string $body): void
    {
        self::assertStringContainsString('intakt.deprecatedReference', $this->checkLegacy($body));
    }

    private function assertLegacyAccepted(string $body): void
    {
        self::assertSame('', $this->checkLegacy($body));
    }

    // because the file-scope fixture's consumer already names Legacy, so only Legacy's own file changes between these cases
    private function checkLegacy(string $body): string
    {
        $sandbox = $this->sandboxWith('deprecated-file-scope');
        $sandbox->write('src/Legacy.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\n".$body);

        return $this->ruleFindingsIn($sandbox);
    }

    private function assertConsumerRefused(string $fixture, string $body): void
    {
        self::assertStringContainsString('intakt.deprecatedReference', $this->checkConsumer($fixture, $body));
    }

    private function assertConsumerAccepted(string $fixture, string $body): void
    {
        self::assertSame('', $this->checkConsumer($fixture, $body));
    }

    private function checkConsumer(string $fixture, string $body): string
    {
        $sandbox = $this->sandboxWith($fixture);
        $sandbox->write('src/Consumer.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\n".$body);

        return $this->ruleFindingsIn($sandbox);
    }
}
