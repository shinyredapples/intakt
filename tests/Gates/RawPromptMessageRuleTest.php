<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\RawPromptMessageRule;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @extends PhpStanRuleTestCase<RawPromptMessageRule>
 */
#[CoversClass(RawPromptMessageRule::class)]
final class RawPromptMessageRuleTest extends PhpStanRuleTestCase
{
    private const array MESSAGE_IMPORTS = [
        'Symfony\AI\Platform\Message\Content\Text',
        'Symfony\AI\Platform\Message\Message',
        'Symfony\AI\Platform\Message\SystemMessage',
        'Symfony\AI\Platform\Message\UserMessage',
    ];

    private const array RAW_MESSAGES = [
        'Message::ofUser($text)',
        'Message::forSystem($text)',
        'Message::ofAssistant($text)',
        'new UserMessage(new Text($text))',
        'new SystemMessage($text)',
    ];

    private const array CONVERSATION_IMPORTS = ['Intakt\Core\Review\Contract\ReviewerConversation', 'Symfony\Component\Uid\UuidV7'];

    private const string NEW_CONVERSATION = 'new ReviewerConversation($text, new UuidV7())';

    protected function ruleFor(string $root): RawPromptMessageRule
    {
        return new RawPromptMessageRule($root);
    }

    public function testEachWayOfBuildingAMessageFromAStringIsRefusedOutsideTheAssembly(): void
    {
        $findings = $this->findingsOfOneClass('src/Report.php', 'Intakt\Sample\Report', self::MESSAGE_IMPORTS, self::RAW_MESSAGES);

        $this->assertRefusedAt($findings, 'src/Report.php', count(self::MESSAGE_IMPORTS), self::RAW_MESSAGES, 'intakt.rawPromptMessage');
    }

    public function testTheAssemblyClassMayBuildMessagesFromStrings(): void
    {
        $findings = $this->findingsOfOneClass('src/Core/Review/Contract/PromptAssembly.php', 'Intakt\Core\Review\Contract\PromptAssembly', self::MESSAGE_IMPORTS, self::RAW_MESSAGES);

        self::assertSame('', $findings);
    }

    public function testAMessageBuiltThroughTheAssemblyFromAFencedTextPasses(): void
    {
        $expressions = ["\$assembly->user('The task:', \$body)", "new MessageBag(\$assembly->system('You review.'), \$assembly->notice('Answer ok.'))"];

        $findings = $this->findingsOfOneClass('src/Report.php', 'Intakt\Sample\Report', ['Intakt\Core\Review\Contract\FencedText', 'Intakt\Core\Review\Contract\PromptAssembly', 'Symfony\AI\Platform\Message\MessageBag'], $expressions, 'PromptAssembly $assembly, FencedText $body');

        self::assertSame('', $findings);
    }

    public function testOtherSpellingsOfTheMessageClassAreStillRefused(): void
    {
        $expressions = ['Prompt::ofUser($text)', 'prompt::OFUSER($text)', '(Prompt::class)::forSystem($text)', 'new (SystemMessage::class)($text)', 'Prompt::ofUser(...)', 'Prompt::{$text}($text)', 'new \symfony\ai\platform\message\usermessage(new Text($text))'];

        $findings = $this->findingsOfOneClass('src/Report.php', 'Intakt\Sample\Report', ['Symfony\AI\Platform\Message\Content\Text', 'Symfony\AI\Platform\Message\Message as Prompt', 'Symfony\AI\Platform\Message\SystemMessage'], $expressions);

        $this->assertRefusedAt($findings, 'src/Report.php', 3, $expressions, 'intakt.rawPromptMessage');
    }

    public function testOtherMessageFactoriesAndUnrelatedStaticCallsPass(): void
    {
        $findings = $this->findingsOfOneClass('src/Report.php', 'Intakt\Sample\Report', ['Symfony\AI\Platform\Message\Message', 'Symfony\AI\Platform\Message\MessageBag', 'Symfony\AI\Platform\Result\ToolCall', 'Symfony\Component\Uid\UuidV7'],['Message::ofToolCall($call, $text)', 'MessageBag::forId(new UuidV7())'], 'ToolCall $call, string $text');

        self::assertSame('', $findings);
    }

    public function testAMessageBuiltInATraitThatTheAssemblyUsesIsRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Review/Contract/Prompts.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Core\\Review\\Contract;\n\nuse Symfony\\AI\\Platform\\Message\\Message;\nuse Symfony\\AI\\Platform\\Message\\UserMessage;\n\ntrait Prompts\n{\n    public function raw(string \$text): UserMessage\n    {\n        return Message::ofUser(\$text);\n    }\n}\n");
        $sandbox->write('src/Core/Review/Contract/PromptAssembly.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Core\\Review\\Contract;\n\nfinal readonly class PromptAssembly\n{\n    use Prompts;\n}\n");

        self::assertStringContainsString('src/Core/Review/Contract/Prompts.php (in context of class Intakt\Core\Review\Contract\PromptAssembly):14 intakt.rawPromptMessage', $this->ruleFindingsIn($sandbox));
    }

    public function testTestsMayBuildMessagesForTheirDoubles(): void
    {
        $findings = $this->findingsOfOneClass('tests/ReportTest.php', 'Intakt\Tests\Sample\ReportTest', [...self::MESSAGE_IMPORTS, ...self::CONVERSATION_IMPORTS], [...self::RAW_MESSAGES, self::NEW_CONVERSATION]);

        self::assertSame('', $findings);
    }

    public function testAConversationBuiltOutsideTheRoleSourcesIsRefused(): void
    {
        $findings = $this->findingsOfOneClass('src/Report.php', 'Intakt\Sample\Report', self::CONVERSATION_IMPORTS, [self::NEW_CONVERSATION, 'new (ReviewerConversation::class)($text, new UuidV7())']);

        $this->assertRefusedAt($findings, 'src/Report.php', 2, [self::NEW_CONVERSATION, 'new (ReviewerConversation::class)'], 'intakt.reviewerConversation');
    }

    public function testTheReviewHandlerMayBuildAConversationFromThePanelRole(): void
    {
        $findings = $this->findingsOfOneClass('src/Core/Review/UseCase/RunReviewRound/ReviewWithReviewerHandler.php', 'Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler', self::CONVERSATION_IMPORTS, [self::NEW_CONVERSATION]);

        self::assertSame('', $findings);
    }

    public function testTheVendorRetryMayCopyAConversationsRolePrompt(): void
    {
        $findings = $this->findingsOfOneClass('src/Plugin/SymfonyAiVendor/Infrastructure/SymfonyAiVendor.php', 'Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor', self::CONVERSATION_IMPORTS, [self::NEW_CONVERSATION]);

        self::assertSame('', $findings);
    }

    public function testTheAssemblyMayNotBuildAConversation(): void
    {
        $findings = $this->findingsOfOneClass('src/Core/Review/Contract/PromptAssembly.php', 'Intakt\Core\Review\Contract\PromptAssembly', self::CONVERSATION_IMPORTS, [self::NEW_CONVERSATION]);

        self::assertStringContainsString('src/Core/Review/Contract/PromptAssembly.php:14 intakt.reviewerConversation', $findings);
    }

    /**
     * @param list<string> $imports
     * @param list<string> $expressions
     */
    private function findingsOfOneClass(string $path, string $class, array $imports, array $expressions, string $parameters = 'string $text'): string
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write($path, $this->classReturning($class, $imports, $expressions, $parameters));

        return $this->ruleFindingsIn($sandbox);
    }

    /**
     * @param list<string> $expressions
     */
    private function assertRefusedAt(string $findings, string $path, int $imports, array $expressions, string $identifier): void
    {
        foreach (array_keys($expressions) as $index) {
            self::assertStringContainsString($path.':'.(12 + $imports + $index * 5).' '.$identifier, $findings, $expressions[$index]);
        }

        self::assertSame(count($expressions), substr_count($findings, $identifier), $findings);
    }

    /**
     * @param list<string> $imports
     * @param list<string> $expressions
     */
    private function classReturning(string $class, array $imports, array $expressions, string $parameters): string
    {
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $name = substr($class, (int) strrpos($class, '\\') + 1);
        $uses = implode('', array_map(static fn (string $import): string => "use {$import};\n", $imports));
        $methods = array_map(
            static fn (int $index, string $expression): string => "    public function case{$index}({$parameters}): mixed\n    {\n        return {$expression};\n    }\n",
            array_keys($expressions),
            $expressions,
        );

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n{$uses}\nfinal readonly class {$name}\n{\n".implode("\n", $methods)."}\n";
    }
}
