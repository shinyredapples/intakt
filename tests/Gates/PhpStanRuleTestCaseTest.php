<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\DeprecatedClasses;
use Intakt\Gates\PhpStan\FileScopeDeprecation;
use Intakt\Gates\PhpStan\NoDeprecatedReferenceRule;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Depends;

/**
 * @extends PhpStanRuleTestCase<NoDeprecatedReferenceRule>
 */
#[CoversNothing]
final class PhpStanRuleTestCaseTest extends PhpStanRuleTestCase
{
    public function testAClassOfTheSandboxIsAnalyzedAsItIsDeclaredThere(): void
    {
        $findings = $this->findingsWithLegacy("/**\n * @deprecated\n */\nclass Legacy\n{\n}\n");

        self::assertStringContainsString('src/Consumer.php:11 intakt.deprecatedReference', $findings);
    }

    #[Depends('testAClassOfTheSandboxIsAnalyzedAsItIsDeclaredThere')]
    public function testAClassNameAnEarlierTestAnalyzedIsReflectedAfreshInTheNextOne(): void
    {
        self::assertSame('', $this->findingsWithLegacy("class Legacy\n{\n}\n"));
    }

    public function testAFileInAFolderTheGateExcludesIsNotAnalyzed(): void
    {
        $sandbox = $this->sandboxWithDeprecatedLegacy();
        $this->writeConsumer($sandbox, 'vendor/acme/package/VendorConsumer.php', 'VendorConsumer');
        $this->writeConsumer($sandbox, 'var/cache/CacheConsumer.php', 'CacheConsumer');
        $this->writeConsumer($sandbox, 'upstream/report/ReproductionConsumer.php', 'ReproductionConsumer');

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testAFolderWhoseNameOnlyStartsLikeAnExcludedOneIsAnalyzed(): void
    {
        $sandbox = $this->sandboxWithDeprecatedLegacy();
        $this->writeConsumer($sandbox, 'variants/VariantConsumer.php', 'VariantConsumer');

        self::assertStringContainsString('variants/VariantConsumer.php:11 intakt.deprecatedReference', $this->ruleFindingsIn($sandbox));
    }

    protected function ruleFor(string $root): NoDeprecatedReferenceRule
    {
        $reflections = self::createReflectionProvider();

        return new NoDeprecatedReferenceRule(new DeprecatedClasses($reflections, new FileScopeDeprecation(self::getParser(), $reflections)));
    }

    private function findingsWithLegacy(string $legacy): string
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Legacy.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\n".$legacy);
        $this->writeConsumer($sandbox, 'src/Consumer.php', 'Consumer');

        return $this->ruleFindingsIn($sandbox);
    }

    private function sandboxWithDeprecatedLegacy(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Legacy.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\n/**\n * @deprecated\n */\nclass Legacy\n{\n}\n");

        return $sandbox;
    }

    private function writeConsumer(Sandbox $sandbox, string $path, string $class): void
    {
        $sandbox->write($path, "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal class ".$class."\n{\n    public function make(): object\n    {\n        return new Legacy();\n    }\n}\n");
    }
}
