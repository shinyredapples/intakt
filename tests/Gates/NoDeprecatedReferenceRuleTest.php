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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(NoDeprecatedReferenceRule::class)]
#[CoversClass(NoDeprecatedDocblockTypeRule::class)]
#[CoversClass(NoDeprecatedClassDocblockTypeRule::class)]
#[CoversClass(DeprecatedDocblockTypes::class)]
#[CoversClass(DeprecatedClasses::class)]
#[CoversClass(FileScopeDeprecation::class)]
#[CoversClass(DocblockTypeNames::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoDeprecatedReferenceRuleTest extends SandboxTestCase
{
    public function testImportingADeprecatedClassFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-import', 'intakt.deprecatedReference');
    }

    public function testCheckingInstanceofAgainstADeprecatedClassFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-instanceof', 'intakt.deprecatedReference');
    }

    public function testCatchingADeprecatedExceptionFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-catch', 'intakt.deprecatedReference');
    }

    public function testADeprecatedClassInADocblockTypeFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-docblock', 'intakt.deprecatedReference');
    }

    public function testTheClassConstantOfADeprecatedClassFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-class-constant', 'intakt.deprecatedReference');
    }

    public function testADeprecatedAttributeClassFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-attribute', 'intakt.deprecatedReference');
    }

    public function testTheNonDeprecatedReplacementPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'deprecated-replacement');
    }

    public function testAClassWhoseFileTriggersADeprecationAtFileScopeFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-file-scope', 'intakt.deprecatedReference');
    }

    public function testATriggerDeprecationInsideAMethodDoesNotMakeTheClassDeprecated(): void
    {
        $this->assertAcceptedBy('phpstan', 'deprecated-trigger-in-method');
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

    public function testTheReplacementInClassLevelDocblockTagsPassesStaticAnalysis(): void
    {
        $this->assertConsumerAccepted('deprecated-replacement', "/**\n * @template T of Modern\n *\n * @property Modern \$item\n *\n * @method Modern make()\n */\nfinal class Consumer\n{\n}\n");
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

    public function testTheReplacementInATraitLevelDocblockPassesStaticAnalysis(): void
    {
        $this->assertConsumerAccepted('deprecated-replacement', "/**\n * @property Modern \$item\n */\ntrait Helper\n{\n}\n\nfinal class Consumer\n{\n    use Helper;\n}\n");
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
        $run = $this->checkConsumer('deprecated-replacement', "use Intakt\\Sample\\{function Legacy};\n\nfinal class Consumer\n{\n}\n");

        self::assertStringNotContainsString('intakt.deprecatedReference', $run->findingsOf('phpstan'), $run->output());
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

    public function testTheReplacementInAUnionAClosureDocblockAndAConstantTypePassesStaticAnalysis(): void
    {
        $this->assertConsumerAccepted('deprecated-replacement', "final class Consumer\n{\n    /**\n     * @param object|Modern \$item\n     * @param Modern::* \$mode\n     */\n    public function take(object \$item, string \$mode): callable\n    {\n        return /** @return Modern */ static fn (): object => new Modern();\n    }\n}\n");
    }

    public function testAnArrayShapeKeyNamedLikeADeprecatedClassIsNoReference(): void
    {
        $this->assertConsumerAccepted('deprecated-replacement', "final class Consumer\n{\n    /**\n     * @return array{Legacy: Modern}\n     */\n    public function shape(): array\n    {\n        return ['Legacy' => new Modern()];\n    }\n}\n");
    }

    public function testATriggerDeprecationInsideAFileScopeClosureDoesNotMakeTheClassDeprecated(): void
    {
        $this->assertLegacyAccepted("const WARN = static function (): void {\n    trigger_deprecation('acme/legacy', '1.0', 'Legacy is deprecated.');\n};\n\nclass Legacy\n{\n}\n");
    }

    private function assertLegacyRefused(string $body): void
    {
        $run = $this->checkLegacy($body);

        self::assertStringContainsString('intakt.deprecatedReference', $run->findingsOf('phpstan'), $run->output());
    }

    private function assertLegacyAccepted(string $body): void
    {
        $run = $this->checkLegacy($body);

        self::assertTrue($run->passed('phpstan'), $run->output());
    }

    // because the file-scope fixture's consumer already names Legacy, so only Legacy's own file changes between these cases
    private function checkLegacy(string $body): CheckRun
    {
        $sandbox = $this->sandboxWith('deprecated-file-scope');
        $sandbox->write('src/Legacy.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\n".$body);

        return $sandbox->check('--only=phpstan');
    }

    private function assertConsumerRefused(string $fixture, string $body): void
    {
        $run = $this->checkConsumer($fixture, $body);

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('intakt.deprecatedReference', $run->findingsOf('phpstan'), $run->output());
    }

    private function assertConsumerAccepted(string $fixture, string $body): void
    {
        $run = $this->checkConsumer($fixture, $body);

        self::assertTrue($run->passed('phpstan'), $run->output());
    }

    private function checkConsumer(string $fixture, string $body): CheckRun
    {
        $sandbox = $this->sandboxWith($fixture);
        $sandbox->write('src/Consumer.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\n".$body);

        return $sandbox->check('--only=phpstan');
    }
}
