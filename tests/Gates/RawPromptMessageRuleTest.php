<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Gates\PhpStan\RawPromptMessageRule;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
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

    public function testTheReviewHandlerMayBuildAConversationFromTheRoundReviewersRolePrompt(): void
    {
        $expressions = ['new ReviewerConversation($reviewer->rolePrompt(), $reviewer->conversationId())', 'new ReviewerConversation(id: new UuidV7(), rolePrompt: $reviewer->rolePrompt())'];

        $findings = $this->ruleFindingsIn($this->sampleSandbox(ReviewWithReviewerHandler::class, self::CONVERSATION_IMPORTS, $expressions));

        self::assertSame('', $findings);
    }

    public function testTheReviewHandlerMayNotBuildAConversationFromAnyOtherText(): void
    {
        $expressions = [
            self::NEW_CONVERSATION,
            'new ReviewerConversation($text, $previous->id)',
            "new ReviewerConversation(\$reviewer->rolePrompt().'!', \$reviewer->conversationId())",
            'new ReviewerConversation(...[$reviewer->rolePrompt(), $reviewer->conversationId()])',
            'new ReviewerConversation(id: $reviewer->conversationId(), rolePrompt: $text)',
            'new ReviewerConversation($previous->rolePrompt, $previous->id)',
            'new ReviewerConversation($call->rolePrompt(), $reviewer->conversationId())',
            'new ReviewerConversation($reviewer?->rolePrompt(), $reviewer->conversationId())',
        ];

        $findings = $this->ruleFindingsIn($this->sampleSandbox(ReviewWithReviewerHandler::class, self::CONVERSATION_IMPORTS, $expressions));

        $this->assertEachCaseRefused($findings, 'intakt.reviewerConversation');
    }

    public function testTheVendorRetryMayCopyAConversationsRolePrompt(): void
    {
        $expressions = ['new ReviewerConversation($previous->rolePrompt, new UuidV7(), askedAgain: true)', 'new ReviewerConversation(id: new UuidV7(), rolePrompt: $previous->rolePrompt)'];

        $findings = $this->ruleFindingsIn($this->sampleSandbox(SymfonyAiVendor::class, self::CONVERSATION_IMPORTS, $expressions));

        self::assertSame('', $findings);
    }

    public function testTheVendorMayNotBuildAConversationFromAnyOtherText(): void
    {
        $expressions = [self::NEW_CONVERSATION, 'new ReviewerConversation($text, $previous->id)', 'new ReviewerConversation(id: $previous->id, rolePrompt: $text)', 'new ReviewerConversation(...[$previous->rolePrompt, $previous->id])', "new ReviewerConversation(\$previous->rolePrompt.'!', \$previous->id)", 'new ReviewerConversation($reviewer->rolePrompt(), $reviewer->conversationId())'];

        $findings = $this->ruleFindingsIn($this->sampleSandbox(SymfonyAiVendor::class, self::CONVERSATION_IMPORTS, $expressions));

        $this->assertEachCaseRefused($findings, 'intakt.reviewerConversation');
    }

    public function testMessagesAndConversationsBuiltInApplicationCodeOutsideSrcAreRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('config/packages/prompt.php', "<?php\n\ndeclare(strict_types=1);\n\nuse Intakt\\Core\\Review\\Contract\\ReviewerConversation;\nuse Symfony\\AI\\Platform\\Message\\Message;\nuse Symfony\\Component\\Uid\\UuidV7;\n\nreturn [\n    Message::ofUser((string) getenv('PROMPT')),\n    new ReviewerConversation((string) getenv('ROLE'), new UuidV7()),\n];\n");
        $sandbox->write('bin/prompt.php', "<?php\n\ndeclare(strict_types=1);\n\nuse Symfony\\AI\\Platform\\Message\\SystemMessage;\n\nreturn new SystemMessage((string) getenv('PROMPT'));\n");

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertStringContainsString('config/packages/prompt.php:10 intakt.rawPromptMessage', $findings);
        self::assertStringContainsString('config/packages/prompt.php:11 intakt.reviewerConversation', $findings);
        self::assertStringContainsString('bin/prompt.php:7 intakt.rawPromptMessage', $findings);
    }

    public function testTheReviewHandlerMayNotBuildItsOwnRoundReviewerFromAPlainString(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Review/UseCase/RunReviewRound/ReviewWithReviewerHandler.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Intakt\Core\Review\UseCase\RunReviewRound;

            use Intakt\Core\Review\Contract\PanelReviewer;
            use Intakt\Core\Review\Contract\ReviewerConversation;
            use Intakt\Core\Review\Contract\ReviewerModel;
            use Intakt\Core\Review\Contract\ReviewerRole;
            use Intakt\Core\Review\Domain\ReviewRound;
            use Intakt\Core\Review\Domain\RoundReviewer;
            use Symfony\Component\Uid\UuidV7;

            final readonly class ReviewWithReviewerHandler
            {
                public function review(string $brief, ReviewRound $round, ReviewerModel $model): ReviewerConversation
                {
                    $role = new ReviewerRole('brief', $brief);
                    $reviewer = new RoundReviewer(new UuidV7(), $round, new PanelReviewer($role, $model, 10));

                    return new ReviewerConversation($reviewer->rolePrompt(), new UuidV7());
                }
            }
            PHP);

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertStringContainsString('ReviewWithReviewerHandler.php:19 intakt.reviewerRole', $findings);
        self::assertStringContainsString('ReviewWithReviewerHandler.php:20 intakt.reviewerRole', $findings);
    }

    #[TestWith([ConfiguredPanels::class])]
    #[TestWith([SampleProject::class])]
    public function testTheOwnersPanelsAndTheSampleProjectMayBuildReviewerRoles(string $builder): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox($builder, [ReviewerRole::class], ['new ReviewerRole($text, $text)', 'new (ReviewerRole::class)($text, $text)']));

        self::assertSame('', $findings);
    }

    public function testTheReviewRoundMayBuildRoundReviewers(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox(ReviewRound::class, [RoundReviewer::class, UuidV7::class], ['new RoundReviewer(new UuidV7(), $this, $text)']));

        self::assertSame('', $findings);
    }

    /**
     * @param list<string> $expressions
     */
    #[DataProvider('constructionsOutsideTheirBuilders')]
    public function testReviewerRolesAndRoundReviewersBuiltInAnyOtherClassAreRefused(string $class, array $expressions): void
    {
        $imports = [ReviewerRole::class, 'Intakt\Core\Review\Contract\ReviewerRole as Role', RoundReviewer::class, UuidV7::class];

        $findings = $this->ruleFindingsIn($this->sampleSandbox($class, $imports, $expressions));

        $this->assertEachCaseRefused($findings, 'intakt.reviewerRole');
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function constructionsOutsideTheirBuilders(): iterable
    {
        $roles = ['new ReviewerRole($text, $text)', 'new Role($text, $text)', 'new (ReviewerRole::class)($text, $text)', 'new \intakt\core\review\contract\reviewerrole($text, $text)'];
        $roundReviewers = ['new RoundReviewer(new UuidV7(), $text, $text)', 'new (RoundReviewer::class)(new UuidV7(), $text, $text)'];

        yield 'any other class' => [self::SAMPLE, [...$roles, ...$roundReviewers]];
        yield 'the review handler' => [ReviewWithReviewerHandler::class, [...$roles, ...$roundReviewers]];
        yield 'the owner\'s panels' => [ConfiguredPanels::class, $roundReviewers];
        yield 'the sample project' => [SampleProject::class, $roundReviewers];
        yield 'the review round' => [ReviewRound::class, $roles];
    }

    public function testReviewerRolesAndRoundReviewersBuiltInATraitOrAConfigurationFileAreRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Review/Domain/Reviewers.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Core\\Review\\Domain;\n\nuse Symfony\\Component\\Uid\\UuidV7;\n\ntrait Reviewers\n{\n    public function reviewer(mixed \$member): RoundReviewer\n    {\n        return new RoundReviewer(new UuidV7(), \$this, \$member);\n    }\n}\n");
        $sandbox->write('src/Core/Review/Domain/ReviewRound.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Core\\Review\\Domain;\n\nfinal class ReviewRound\n{\n    use Reviewers;\n}\n");
        $sandbox->write('config/packages/panel.php', "<?php\n\ndeclare(strict_types=1);\n\nuse Intakt\\Core\\Review\\Contract\\ReviewerRole;\nuse Intakt\\Core\\Review\\Domain\\RoundReviewer;\nuse Symfony\\Component\\Uid\\UuidV7;\n\nreturn [\n    new ReviewerRole('brief', (string) getenv('BRIEF')),\n    new RoundReviewer(new UuidV7(), null, null),\n];\n");

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertStringContainsString('src/Core/Review/Domain/Reviewers.php (in context of class Intakt\Core\Review\Domain\ReviewRound):13 intakt.reviewerRole', $findings);
        self::assertStringContainsString('config/packages/panel.php:10 intakt.reviewerRole', $findings);
        self::assertStringContainsString('config/packages/panel.php:11 intakt.reviewerRole', $findings);
    }

    public function testTheAssemblyMayNotBuildAConversation(): void
    {
        $findings = $this->ruleFindingsIn($this->sampleSandbox(PromptAssembly::class, self::CONVERSATION_IMPORTS, [self::NEW_CONVERSATION]));

        $this->assertEachCaseRefused($findings, 'intakt.reviewerConversation');
    }
}
