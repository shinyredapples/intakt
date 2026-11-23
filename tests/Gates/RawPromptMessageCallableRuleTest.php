<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\RawPromptMessageCallableRule;
use Intakt\Gates\PhpStan\RawPromptMessageRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<RawPromptMessageCallableRule>
 */
#[CoversClass(RawPromptMessageCallableRule::class)]
#[UsesClass(RawPromptMessageRule::class)]
final class RawPromptMessageCallableRuleTest extends PhpStanRuleTestCase
{
    private const string SAMPLE = "<?php\n\ndeclare(strict_types=1);\n\nnamespace %s;\n\nuse Symfony\\AI\\Platform\\Message\\Message;\n\nfinal readonly class %s\n{\n    public function messages(string \$text): mixed\n    {\n        return array_map(Message::ofUser(...), [\$text]);\n    }\n\n    public function factory(Message \$message): mixed\n    {\n        return \$message::forSystem(...);\n    }\n}\n";

    public function testAFirstClassCallableOfAMessageFactoryOutsideTheAssemblyIsRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Report.php', sprintf(self::SAMPLE, 'Intakt\Sample', 'Report'));

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertStringContainsString('src/Sample/Report.php:13 intakt.rawPromptMessage Message::ofUser() builds a model message outside PromptAssembly', $findings);
        self::assertStringContainsString('src/Sample/Report.php:18 intakt.rawPromptMessage Message::forSystem()', $findings);
    }

    public function testAFirstClassCallableNamedThroughAKnownStringIsRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Report.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nuse Symfony\\AI\\Platform\\Message\\Message;\n\nfunction messages(): mixed\n{\n    return Message::{'ofUser'}(...);\n}\n");

        self::assertStringContainsString('src/Sample/Report.php:11 intakt.rawPromptMessage Message::ofUser()', $this->ruleFindingsIn($sandbox));
    }

    public function testTheAssemblyClassMayUseAMessageFactoryAsACallable(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Review/Contract/PromptAssembly.php', sprintf(self::SAMPLE, 'Intakt\Core\Review\Contract', 'PromptAssembly'));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testACallableUnderTestsIsNotJudged(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/Sample/ReportTest.php', sprintf(self::SAMPLE, 'Intakt\Tests\Sample', 'ReportTest'));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    protected function ruleFor(string $root): RawPromptMessageCallableRule
    {
        return new RawPromptMessageCallableRule($root);
    }
}
