<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Gates\PhpStan\RawPromptCallableRule;
use Intakt\Gates\PhpStan\RawPromptMessageRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Message\Message;

/**
 * @extends PhpStanRuleTestCase<RawPromptCallableRule>
 */
#[CoversClass(RawPromptCallableRule::class)]
#[UsesClass(RawPromptMessageRule::class)]
final class RawPromptCallableRuleTest extends PhpStanRuleTestCase
{
    use PromptSamples;

    private const array CALLABLES = ['Message::ofUser(...)', 'Message::forSystem(...)', 'Prompt::ofAssistant(...)'];

    protected function ruleFor(string $root): RawPromptCallableRule
    {
        return new RawPromptCallableRule($root);
    }

    public function testAMessageFactoryTakenAsACallableIsRefusedOutsideTheAssembly(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox('Intakt\Sample\Report', [Message::class, 'Symfony\AI\Platform\Message\Message as Prompt'], self::CALLABLES));

        $this->assertEachCaseRefused($findings, 'intakt.rawPromptMessage');
    }

    public function testTheAssemblyMayTakeAMessageFactoryAsACallable(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox(PromptAssembly::class, [Message::class, 'Symfony\AI\Platform\Message\Message as Prompt'], self::CALLABLES));

        self::assertSame('', $findings);
    }

    public function testOtherCallablesPass(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox('Intakt\Sample\Report', [Message::class], ['Message::ofToolCall(...)', '$assembly->notice(...)']));

        self::assertSame('', $findings);
    }

    public function testAMessageFactoryTakenAsACallableInApplicationConfigurationIsRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('config/packages/prompt.php', "<?php\n\ndeclare(strict_types=1);\n\nuse Symfony\\AI\\Platform\\Message\\Message;\n\nreturn Message::ofUser(...);\n");

        self::assertStringContainsString('config/packages/prompt.php:7 intakt.rawPromptMessage', $this->ruleFindingsIn($sandbox));
    }

    public function testTestsMayTakeAMessageFactoryAsACallable(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox('Intakt\Tests\Sample\ReportTest', [Message::class], ['Message::ofUser(...)']));

        self::assertSame('', $findings);
    }
}
