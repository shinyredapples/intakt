<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\DeprecatedClasses;
use Intakt\Gates\PhpStan\FileScopeDeprecation;
use Intakt\Gates\PhpStan\NoDeprecatedDocblockTypeRule;
use Intakt\Gates\PhpStan\NoDeprecatedReferenceRule;
use Intakt\Gates\PhpStan\OutermostTypeNodes;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(NoDeprecatedReferenceRule::class)]
#[CoversClass(NoDeprecatedDocblockTypeRule::class)]
#[CoversClass(DeprecatedClasses::class)]
#[CoversClass(FileScopeDeprecation::class)]
#[CoversClass(OutermostTypeNodes::class)]
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
        $sandbox = $this->sandboxWith('deprecated-file-scope');
        $sandbox->write('src/Legacy.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nif ('disabled' !== getenv('LEGACY_DEPRECATION')) {\n    trigger_deprecation('acme/legacy', '1.0', 'Legacy is deprecated.');\n}\n\nclass Legacy\n{\n}\n");

        $run = $sandbox->check('--only=phpstan');

        self::assertStringContainsString('intakt.deprecatedReference', $run->findingsOf('phpstan'), $run->output());
    }

    public function testATriggerDeprecationInsideAFileScopeClosureDoesNotMakeTheClassDeprecated(): void
    {
        $sandbox = $this->sandboxWith('deprecated-file-scope');
        $sandbox->write('src/Legacy.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nconst WARN = static function (): void {\n    trigger_deprecation('acme/legacy', '1.0', 'Legacy is deprecated.');\n};\n\nclass Legacy\n{\n}\n");

        $run = $sandbox->check('--only=phpstan');

        self::assertTrue($run->passed('phpstan'), $run->output());
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
