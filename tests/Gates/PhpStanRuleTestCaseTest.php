<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\DeprecatedClasses;
use Intakt\Gates\PhpStan\FileScopeDeprecation;
use Intakt\Gates\PhpStan\NoDeprecatedReferenceRule;
use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Depends;

#[CoversNothing]
final class PhpStanRuleTestCaseTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): Rule
    {
        $reflections = self::createReflectionProvider();

        return new NoDeprecatedReferenceRule(new DeprecatedClasses($reflections, new FileScopeDeprecation(self::getParser(), $reflections)));
    }

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

    private function findingsWithLegacy(string $legacy): string
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Legacy.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\n".$legacy);
        $sandbox->write('src/Consumer.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal class Consumer\n{\n    public function make(): object\n    {\n        return new Legacy();\n    }\n}\n");

        return $this->ruleFindingsIn($sandbox);
    }
}
