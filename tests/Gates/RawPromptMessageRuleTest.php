<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Gates\PhpStan\RawPromptMessageRule;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Component\Uid\UuidV7;

/**
 * @extends PhpStanRuleTestCase<RawPromptMessageRule>
 */
#[CoversClass(RawPromptMessageRule::class)]
final class RawPromptMessageRuleTest extends PhpStanRuleTestCase
{
    use PromptSamples;

    private const string SAMPLE = 'Intakt\Sample\Report';

    private const array MESSAGE_IMPORTS = [Text::class, Message::class, SystemMessage::class, UserMessage::class];

    private const array RAW_MESSAGES = [
        'Message::ofUser($text)',
        'Message::forSystem($text)',
        'Message::ofAssistant($text)',
        'new UserMessage(new Text($text))',
        'new SystemMessage($text)',
    ];

    private const array CONVERSATION_IMPORTS = [ReviewerConversation::class, UuidV7::class];

    private const string NEW_CONVERSATION = 'new ReviewerConversation($text, new UuidV7())';

    protected function ruleFor(string $root): RawPromptMessageRule
    {
        return new RawPromptMessageRule($root);
    }

    public function testEachWayOfBuildingAMessageFromAStringIsRefusedOutsideTheAssembly(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox(self::SAMPLE, self::MESSAGE_IMPORTS, self::RAW_MESSAGES));

        $this->assertEachCaseRefused($findings, 'intakt.rawPromptMessage');
    }

    public function testTheAssemblyClassMayBuildMessagesFromStrings(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox(PromptAssembly::class, self::MESSAGE_IMPORTS, self::RAW_MESSAGES));

        self::assertSame('', $findings);
    }

    public function testAMessageBuiltThroughTheAssemblyFromAFencedTextPasses(): void
    {
        $expressions = ["\$assembly->user('The task:', \$body)", "new MessageBag(\$assembly->system('You review.'), \$assembly->notice('Answer ok.'))"];

        $findings = $this->ruleFindingsIn($this->sampleSandbox(self::SAMPLE, [MessageBag::class], $expressions));

        self::assertSame('', $findings);
    }

    public function testOtherSpellingsOfTheMessageClassAreStillRefused(): void
    {
        $expressions = ['Prompt::ofUser($text)', 'prompt::OFUSER($text)', '(Prompt::class)::forSystem($text)', 'new (SystemMessage::class)($text)', 'Prompt::{$text}($text)', 'new \symfony\ai\platform\message\usermessage(new Text($text))'];

        $findings = $this->ruleFindingsIn($this->sampleSandbox(self::SAMPLE, [Text::class, 'Symfony\AI\Platform\Message\Message as Prompt', SystemMessage::class], $expressions));

        $this->assertEachCaseRefused($findings, 'intakt.rawPromptMessage');
    }

    public function testOtherMessageFactoriesAndUnrelatedStaticCallsPass(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox(self::SAMPLE, [Message::class, MessageBag::class, UuidV7::class], ['Message::ofToolCall($call, $text)', 'MessageBag::forId(new UuidV7())']));

        self::assertSame('', $findings);
    }

    public function testAMessageBuiltInATraitThatTheAssemblyUsesIsRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Review/Contract/Prompts.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Core\\Review\\Contract;\n\nuse Symfony\\AI\\Platform\\Message\\Message;\nuse Symfony\\AI\\Platform\\Message\\UserMessage;\n\ntrait Prompts\n{\n    public function raw(string \$text): UserMessage\n    {\n        return Message::ofUser(\$text);\n    }\n}\n");
        $sandbox->write('src/Core/Review/Contract/PromptAssembly.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Core\\Review\\Contract;\n\nfinal readonly class PromptAssembly\n{\n    use Prompts;\n}\n");

        self::assertStringContainsString('src/Core/Review/Contract/Prompts.php (in context of class Intakt\Core\Review\Contract\PromptAssembly):14 intakt.rawPromptMessage', $this->ruleFindingsIn($sandbox));
    }

    public function testTestsMayBuildMessagesAndConversationsForTheirDoubles(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox('Intakt\Tests\Sample\ReportTest', [...self::MESSAGE_IMPORTS, ...self::CONVERSATION_IMPORTS], [...self::RAW_MESSAGES, self::NEW_CONVERSATION]));

        self::assertSame('', $findings);
    }

    public function testAConversationBuiltOutsideTheRoleSourcesIsRefused(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox(self::SAMPLE, self::CONVERSATION_IMPORTS, [self::NEW_CONVERSATION, 'new (ReviewerConversation::class)($text, new UuidV7())']));

        $this->assertEachCaseRefused($findings, 'intakt.reviewerConversation');
    }

    public function testTheReviewHandlerMayBuildAConversationFromThePanelRole(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox(ReviewWithReviewerHandler::class, self::CONVERSATION_IMPORTS, [self::NEW_CONVERSATION]));

        self::assertSame('', $findings);
    }

    public function testTheVendorRetryMayCopyAConversationsRolePrompt(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox(SymfonyAiVendor::class, self::CONVERSATION_IMPORTS, [self::NEW_CONVERSATION]));

        self::assertSame('', $findings);
    }

    public function testTheAssemblyMayNotBuildAConversation(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox(PromptAssembly::class, self::CONVERSATION_IMPORTS, [self::NEW_CONVERSATION]));

        $this->assertEachCaseRefused($findings, 'intakt.reviewerConversation');
    }
}
