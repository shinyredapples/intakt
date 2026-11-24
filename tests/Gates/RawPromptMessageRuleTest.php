<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Gates\PhpStan\ClassNames;
use Intakt\Gates\PhpStan\RawPromptMessageRule;
use Intakt\Gates\PhpStan\RolePromptCarriers;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @extends PhpStanRuleTestCase<RawPromptMessageRule>
 */
#[CoversClass(RawPromptMessageRule::class)]
#[CoversClass(RolePromptCarriers::class)]
#[CoversClass(ClassNames::class)]
final class RawPromptMessageRuleTest extends PhpStanRuleTestCase
{
    private const string MESSAGE_IMPORTS = "use Symfony\\AI\\Platform\\Message\\Content\\Text;\nuse Symfony\\AI\\Platform\\Message\\Message;\nuse Symfony\\AI\\Platform\\Message\\SystemMessage;\nuse Symfony\\AI\\Platform\\Message\\UserMessage;\n";
    private const string IMPORTS = self::MESSAGE_IMPORTS."use Intakt\\Core\\Review\\Contract\\ReviewerConversation;\nuse Intakt\\Core\\Review\\Domain\\RoundReviewer;\nuse Symfony\\Component\\Uid\\UuidV7;\n".self::ROLE_IMPORTS;
    private const string ROLE_IMPORTS = "use Intakt\\Core\\Review\\Contract\\ReviewerRole;\nuse Intakt\\Core\\Review\\Contract\\PanelReviewer;\nuse Intakt\\Core\\Review\\Contract\\ReviewerRole as Role;\nuse Intakt\\Core\\Review\\Domain\\RoundReviewer as Seat;\n";
    private const string HANDLER = 'src/Core/Review/UseCase/RunReviewRound/ReviewWithReviewerHandler.php';
    private const string VENDOR = 'src/Plugin/SymfonyAiVendor/Infrastructure/SymfonyAiVendor.php';
    private const string BUILDER_PARAMETERS = '(string $text, UuidV7 $id, \Intakt\Core\Review\Domain\ReviewRound $round, PanelReviewer $member, ReviewerConversation $stopped, RoundReviewer $reviewer, ?ReviewerConversation $maybe, array $arguments)';
    private const int FIRST_CASE_LINE = 23;
    private const int TRAIT_CASE_LINE = 14;

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rawMessages(): iterable
    {
        yield 'the message factories' => [['Message::ofUser($text)', "Message::forSystem('Be brief.')", 'Message::ofAssistant($text)', 'message::OFUSER($text)', '\Symfony\AI\Platform\Message\Message::forSystem($text)']];
        yield 'the message classes' => [['new UserMessage(new Text($text))', 'new SystemMessage($text)', 'new \Symfony\AI\Platform\Message\UserMessage(new Text($text))', 'new (SystemMessage::class)($text)']];
        yield 'less direct forms' => [['$message::ofUser($text)', '(Message::class)::forSystem($text)', "new ('Symfony\\AI\\Platform\\Message\\UserMessage')(new Text(\$text))"]];
    }

    /**
     * @param list<string> $expressions
     */
    #[DataProvider('rawMessages')]
    public function testAMessageBuiltFromAStringOutsideTheAssemblyIsRefused(array $expressions): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Report.php', $this->classWithCases('Intakt\Sample\\Report', '(string $text, Message $message)', $expressions));

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertSame(count($expressions), substr_count($findings, 'intakt.rawPromptMessage'), $findings);
        foreach (array_keys($expressions) as $index) {
            self::assertStringContainsString('src/Sample/Report.php:'.(self::FIRST_CASE_LINE + $index * 5).' intakt.rawPromptMessage', $findings, $expressions[$index]);
        }
    }

    public function testTheFindingSaysToBuildTheMessageThroughTheAssembly(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Report.php', $this->classWithCases('Intakt\Sample\\Report', '(string $text)', ['Message::ofUser($text)']));

        self::assertStringContainsString('Message::ofUser() builds a model message outside PromptAssembly: build it through Intakt\Core\Review\Contract\PromptAssembly', $this->ruleFindingsIn($sandbox));
    }

    public function testAFactoryNamedThroughAKnownStringIsRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Report.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Intakt\Sample;

            use Symfony\AI\Platform\Message\Message;

            final readonly class Report
            {
                public function write(string $text, bool $system): void
                {
                    Message::{'ofUser'}($text);
                    $method = 'forSystem';
                    Message::$method($text);
                    $either = $system ? 'forSystem' : 'ofAssistant';
                    Message::$either($text);
                }
            }
            PHP);

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertSame(3, substr_count($findings, 'intakt.rawPromptMessage'), $findings);
        foreach ([13, 15, 17] as $line) {
            self::assertStringContainsString('src/Sample/Report.php:'.$line.' intakt.rawPromptMessage', $findings);
        }
    }

    public function testAMethodNamedThroughAnUnknownOrOtherStringIsNotJudged(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Report.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Intakt\Sample;

            use Symfony\AI\Platform\Message\Message;

            final readonly class Report
            {
                public function write(string $text, string $method): mixed
                {
                    $other = 'ofUserLater';

                    return [Message::$method($text), Message::$other($text)];
                }
            }
            PHP);

        self::assertStringNotContainsString('intakt.rawPromptMessage', $this->ruleFindingsIn($sandbox));
    }

    public function testATypedClassStringNamingAMessageClassIsRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Report.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Intakt\Sample;

            use Symfony\AI\Platform\Message\Message;
            use Symfony\AI\Platform\Message\SystemMessage;

            final readonly class Report
            {
                /**
                 * @param class-string<Message> $factory
                 * @param class-string<SystemMessage> $message
                 */
                public function write(string $text, string $factory, string $message): void
                {
                    $factory::ofUser($text);
                    new $message($text);
                }
            }
            PHP);

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertStringContainsString('src/Sample/Report.php:18 intakt.rawPromptMessage', $findings);
        self::assertStringContainsString('src/Sample/Report.php:19 intakt.rawPromptMessage', $findings);
    }

    public function testATypedClassStringNamingAnotherClassPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Report.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Intakt\Sample;

            final readonly class Report
            {
                /**
                 * @param class-string<\ArrayObject<int, string>> $class
                 */
                public function write(string $text, string $class, string $anyClass): array
                {
                    return [new $class([$text]), new $anyClass($text)];
                }
            }
            PHP);

        self::assertStringNotContainsString('intakt.rawPromptMessage', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function callableForms(): iterable
    {
        yield 'a literal array' => ['[Message::class, \'ofUser\']'];
        yield 'a keyed reordered array' => ['[1 => \'ofUser\', 0 => Message::class]'];
        yield 'a method known only at run time' => ['[Message::class, $method]'];
        yield 'a nullable method known only at run time' => ['[Message::class, $nullableMethod]'];
        yield 'a single backslash class in an array' => ['[\'\\Symfony\\AI\\Platform\\Message\\Message\', \'ofUser\']'];
        yield 'a callable string' => ['\'Symfony\\AI\\Platform\\Message\\Message::forSystem\''];
        yield 'a single backslash callable string' => ['\'\\Symfony\\AI\\Platform\\Message\\Message::forSystem\''];
        yield 'a static call on a single backslash class string' => ['(\'\\Symfony\\AI\\Platform\\Message\\Message\')::ofUser($text)'];
        yield 'new on a single backslash class string' => ['new (\'\\Symfony\\AI\\Platform\\Message\\UserMessage\')(new Text($text))'];
    }

    #[DataProvider('callableForms')]
    public function testALiteralCallableOfAMessageFactoryOutsideTheAssemblyIsRefused(string $expression): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Report.php', $this->classWithCases('Intakt\Sample\\Report', '(string $text, string $method, ?string $nullableMethod, int $number)', [$expression]));

        self::assertStringContainsString('src/Sample/Report.php:'.self::FIRST_CASE_LINE.' intakt.rawPromptMessage', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonCallableForms(): iterable
    {
        yield 'an associative array' => ['[\'class\' => Message::class, \'method\' => \'ofUser\']'];
        yield 'an array with a third key' => ['[Message::class, \'ofUser\', $text]'];
        yield 'the keys 1 and 2' => ['[1 => \'ofUser\', 2 => Message::class]'];
        yield 'another class' => ['[\\ArrayObject::class, \'ofUser\']'];
        yield 'another method' => ['[Message::class, \'ofUserLater\']'];
        yield 'a number in place of the method' => ['[Message::class, $number]'];
        yield 'two leading backslashes in an array' => ['[\'\\\\\\\\Symfony\\AI\\Platform\\Message\\Message\', \'ofUser\']'];
        yield 'two leading backslashes in a callable string' => ['\'\\\\\\\\Symfony\\AI\\Platform\\Message\\Message::ofUser\''];
    }

    #[DataProvider('nonCallableForms')]
    public function testAnythingButALiteralMessageCallablePasses(string $expression): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Report.php', $this->classWithCases('Intakt\Sample\\Report', '(string $text, string $method, ?string $nullableMethod, int $number)', [$expression]));

        self::assertStringNotContainsString('intakt.rawPromptMessage', $this->ruleFindingsIn($sandbox));
    }

    public function testATraitUnderSrcUsedOnlyUnderTestsIsJudgedByItsOwnFile(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/BuildsMessages.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nuse Symfony\\AI\\Platform\\Message\\Message;\n\ntrait BuildsMessages\n{\n    public function build(string \$text): mixed\n    {\n        return Message::forSystem(\$text);\n    }\n}\n");
        $sandbox->write('tests/Sample/ReportTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Tests\\Sample;\n\nuse Intakt\\Sample\\BuildsMessages;\n\nfinal class ReportTest\n{\n    use BuildsMessages;\n}\n");

        self::assertStringContainsString('src/Sample/BuildsMessages.php (in context of class Intakt\Tests\Sample\ReportTest):13 intakt.rawPromptMessage', $this->ruleFindingsIn($sandbox));
    }

    public function testTheAssemblyClassMayBuildMessages(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Review/Contract/PromptAssembly.php', $this->classWithCases(PromptAssembly::class, '(string $text)', ['Message::forSystem($text)', 'Message::ofUser($text)', 'new UserMessage(new Text($text))', 'array_map(static fn (string $line): SystemMessage => new SystemMessage($line), [$text])']));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testAClassBesideTheAssemblyMayNotBuildMessages(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Review/Contract/PromptNotes.php', $this->classWithCases('Intakt\Core\Review\Contract\\PromptNotes', '(string $text)', ['Message::ofUser($text)']));

        self::assertStringContainsString('src/Core/Review/Contract/PromptNotes.php:'.self::FIRST_CASE_LINE.' intakt.rawPromptMessage', $this->ruleFindingsIn($sandbox));
    }

    public function testAMessageBuiltThroughTheAssemblyFromAFencedTextPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Briefing.php', $this->classWithCases('Intakt\Sample\Briefing', '(string $task, \Intakt\Core\Review\Contract\PromptAssembly $assembly, \Intakt\Core\Review\Contract\Fence $fence, ReviewerConversation $conversation)', [
            "\$assembly->user('The task:', \$fence->wrap(\\Intakt\\Core\\Review\\Contract\\UntrustedSource::Task, \$task))",
            "\$assembly->notice('Answer with the single word: ok')",
            "\$assembly->systemWithRole('You review.', \$conversation)",
        ]));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function judgedFolders(): iterable
    {
        yield 'src' => ['src/Sample/Report.php'];
        yield 'bin' => ['bin/Report.php'];
        yield 'config' => ['config/Report.php'];
    }

    #[DataProvider('judgedFolders')]
    public function testEachJudgedFolderIsJudged(string $path): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write($path, $this->classWithCases('Intakt\Sample\\Report', '(string $text)', ['Message::ofUser($text)']));

        self::assertStringContainsString($path.':'.self::FIRST_CASE_LINE.' intakt.rawPromptMessage', $this->ruleFindingsIn($sandbox));
    }

    public function testAMessageBuiltUnderTestsIsNotJudged(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/Sample/ReportTest.php', $this->classWithCases('Intakt\Tests\Sample\\ReportTest', '(string $text)', ['Message::ofUser($text)', 'new SystemMessage($text)']));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testTheRoundReviewersRolePromptMayEnterTheHandlersConversation(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(self::HANDLER, $this->handlerWithCases(['new ReviewerConversation($reviewer->rolePrompt(), $id)', 'new ReviewerConversation(id: $id, rolePrompt: $reviewer->rolePrompt())']));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unprovenRolePrompts(): iterable
    {
        yield 'a literal' => ["new ReviewerConversation('Review harder.', \$id)"];
        yield 'a plain string' => ['new ReviewerConversation($text, $id)'];
        yield 'a named plain string' => ['new ReviewerConversation(id: $id, rolePrompt: $text)'];
        yield 'another conversation\'s role prompt' => ['new ReviewerConversation($conversation->rolePrompt, $id)'];
        yield 'a nullsafe call' => ['new ReviewerConversation((string) $maybe?->rolePrompt(), $id)'];
        yield 'unpacked arguments' => ['new ReviewerConversation(...$arguments)'];
        yield 'an unpacked list holding the role prompt' => ['new ReviewerConversation(...[$reviewer->rolePrompt(), $id])'];
        yield 'an unpack after the role prompt' => ['new ReviewerConversation($reviewer->rolePrompt(), ...[$id])'];
        yield 'no arguments' => ['new ReviewerConversation()'];
    }

    #[DataProvider('unprovenRolePrompts')]
    public function testAnyOtherRolePromptInTheHandlersConversationIsRefused(string $expression): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(self::HANDLER, $this->handlerWithCases([$expression]));

        self::assertStringContainsString(self::HANDLER.':'.self::FIRST_CASE_LINE.' intakt.unprovenRolePrompt', $this->ruleFindingsIn($sandbox));
    }

    public function testAHandlerThatBuildsItsOwnRoundReviewerFromAPlainStringIsRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(self::HANDLER, <<<'PHP'
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
                public function review(string $brief, UuidV7 $id, ReviewRound $round, ReviewerModel $model): ReviewerConversation
                {
                    $role = new ReviewerRole('correctness', $brief);
                    $member = new PanelReviewer($role, $model, 1);
                    $reviewer = new RoundReviewer($id, $round, $member);

                    return new ReviewerConversation($reviewer->rolePrompt(), $id);
                }
            }
            PHP);

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertSame(2, substr_count($findings, 'intakt.reviewerRole'), $findings);
        self::assertStringContainsString(self::HANDLER.':19 intakt.reviewerRole', $findings);
        self::assertStringContainsString(self::HANDLER.':21 intakt.reviewerRole', $findings);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function allowedBuilders(): iterable
    {
        yield 'the owner\'s panel configuration builds a role' => ['src/Core/Review/Infrastructure/ConfiguredPanels.php', ConfiguredPanels::class, 'new ReviewerRole(\'correctness\', $text)'];
        yield 'the sample project builds a literal role' => ['src/Core/Review/UseCase/SeedSampleProject/SampleProject.php', SampleProject::class, 'new ReviewerRole(\'security\', \'Look for ways the change lets input run commands.\')'];
        yield 'the round builds its reviewer from a panel member' => ['src/Core/Review/Domain/ReviewRound.php', ReviewRound::class, 'new RoundReviewer($id, $round, $member)'];
        yield 'the handler builds a conversation from its round reviewer' => [self::HANDLER, ReviewWithReviewerHandler::class, 'new ReviewerConversation($reviewer->rolePrompt(), $id)'];
        yield 'the vendor\'s retry copies the stopped conversation\'s role prompt' => [self::VENDOR, SymfonyAiVendor::class, 'new ReviewerConversation($stopped->rolePrompt, $id, askedAgain: true)'];
        yield 'the vendor\'s retry names the copied role prompt' => [self::VENDOR, SymfonyAiVendor::class, 'new ReviewerConversation(id: $id, rolePrompt: $stopped->rolePrompt)'];
    }

    #[DataProvider('allowedBuilders')]
    public function testTheClassesThatCarryTheOwnersRolePromptMayBuildItsObjects(string $path, string $class, string $expression): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write($path, $this->classWithCases($class, self::BUILDER_PARAMETERS, [$expression]));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function misplacedRoles(): iterable
    {
        yield 'a role in another src class' => ['src/Sample/Report.php', 'Intakt\Sample\Report', 'new ReviewerRole(\'x\', $text)'];
        yield 'an aliased role' => ['src/Sample/Report.php', 'Intakt\Sample\Report', 'new Role(\'x\', $text)'];
        yield 'a role through its class constant' => ['src/Sample/Report.php', 'Intakt\Sample\Report', 'new (ReviewerRole::class)(\'x\', $text)'];
        yield 'a role through a class-name string' => ['src/Sample/Report.php', 'Intakt\Sample\Report', 'new (\'\\Intakt\\Core\\Review\\Contract\\ReviewerRole\')(\'x\', $text)'];
        yield 'a role in another letter case' => ['src/Sample/Report.php', 'Intakt\Sample\Report', 'new reviewerrole(\'x\', $text)'];
        yield 'a role in the assembly' => ['src/Core/Review/Contract/PromptAssembly.php', PromptAssembly::class, 'new ReviewerRole(\'x\', $text)'];
        yield 'a role in the handler' => [self::HANDLER, ReviewWithReviewerHandler::class, 'new ReviewerRole(\'x\', $text)'];
        yield 'a role in the round' => ['src/Core/Review/Domain/ReviewRound.php', ReviewRound::class, 'new ReviewerRole(\'x\', $text)'];
        yield 'a role in a class beside the panel configuration' => ['src/Core/Review/Infrastructure/ConfiguredPanelsCache.php', 'Intakt\Core\Review\Infrastructure\ConfiguredPanelsCache', 'new ReviewerRole(\'x\', $text)'];
        yield 'a role in a config file' => ['config/Report.php', 'Intakt\Sample\Report', 'new ReviewerRole(\'x\', $text)'];
        yield 'a round reviewer in another src class' => ['src/Sample/Report.php', 'Intakt\Sample\Report', 'new RoundReviewer($id, $round, $member)'];
        yield 'an aliased round reviewer' => ['src/Sample/Report.php', 'Intakt\Sample\Report', 'new Seat($id, $round, $member)'];
        yield 'a round reviewer through a class-name string' => ['src/Sample/Report.php', 'Intakt\Sample\Report', 'new (\'Intakt\\Core\\Review\\Domain\\RoundReviewer\')($id, $round, $member)'];
        yield 'a round reviewer in the handler' => [self::HANDLER, ReviewWithReviewerHandler::class, 'new RoundReviewer($id, $round, $member)'];
        yield 'a round reviewer in the panel configuration' => ['src/Core/Review/Infrastructure/ConfiguredPanels.php', ConfiguredPanels::class, 'new RoundReviewer($id, $round, $member)'];
        yield 'a round reviewer in a config file' => ['config/Report.php', 'Intakt\Sample\Report', 'new RoundReviewer($id, $round, $member)'];
    }

    #[DataProvider('misplacedRoles')]
    public function testARoleOrRoundReviewerBuiltOutsideItsOwnClassesIsRefused(string $path, string $class, string $expression): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write($path, $this->classWithCases($class, self::BUILDER_PARAMETERS, [$expression]));

        self::assertStringContainsString($path.':'.self::FIRST_CASE_LINE.' intakt.reviewerRole', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function misplacedConversations(): iterable
    {
        yield 'a conversation in another plugin' => ['src/Plugin/Sample/Retry.php', 'Intakt\Plugin\Sample\Retry', 'new ReviewerConversation($stopped->rolePrompt, $id)'];
        yield 'a conversation through a class-name string' => ['src/Plugin/Sample/Retry.php', 'Intakt\Plugin\Sample\Retry', 'new (\'Intakt\\Core\\Review\\Contract\\ReviewerConversation\')($text, $id)'];
        yield 'a conversation in another letter case' => ['src/Plugin/Sample/Retry.php', 'Intakt\Plugin\Sample\Retry', 'new reviewerCONVERSATION($text, $id)'];
        yield 'a conversation in a class beside the vendor' => ['src/Plugin/SymfonyAiVendor/Infrastructure/SymfonyAiVendorProbe.php', 'Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendorProbe', 'new ReviewerConversation($stopped->rolePrompt, $id)'];
        yield 'a conversation in the panel configuration' => ['src/Core/Review/Infrastructure/ConfiguredPanels.php', ConfiguredPanels::class, 'new ReviewerConversation($text, $id)'];
        yield 'a conversation in a config file' => ['config/Report.php', 'Intakt\Sample\Report', 'new ReviewerConversation($text, $id)'];
    }

    #[DataProvider('misplacedConversations')]
    public function testAConversationBuiltOutsideTheHandlerAndTheVendorsRetryIsRefused(string $path, string $class, string $expression): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write($path, $this->classWithCases($class, self::BUILDER_PARAMETERS, [$expression]));

        self::assertStringContainsString($path.':'.self::FIRST_CASE_LINE.' intakt.reviewerConversation', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uncopiedRolePrompts(): iterable
    {
        yield 'a plain string' => ['new ReviewerConversation($text, $id)'];
        yield 'a literal' => ["new ReviewerConversation('Review harder.', \$id)"];
        yield 'a round reviewer\'s role prompt' => ['new ReviewerConversation($reviewer->rolePrompt(), $id)'];
        yield 'a nullsafe copy' => ['new ReviewerConversation((string) $maybe?->rolePrompt, $id)'];
        yield 'another property of the conversation' => ['new ReviewerConversation($stopped->id->toRfc4122(), $id)'];
        yield 'unpacked arguments' => ['new ReviewerConversation(...$arguments)'];
    }

    #[DataProvider('uncopiedRolePrompts')]
    public function testAnyRolePromptButACopiedOneInTheVendorsConversationIsRefused(string $expression): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(self::VENDOR, $this->classWithCases(SymfonyAiVendor::class, self::BUILDER_PARAMETERS, [$expression]));

        self::assertStringContainsString(self::VENDOR.':'.self::FIRST_CASE_LINE.' intakt.unprovenRolePrompt', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function traitBuilders(): iterable
    {
        yield 'a role' => [ConfiguredPanels::class, 'new \Intakt\Core\Review\Contract\ReviewerRole(\'x\', $text)', 'intakt.reviewerRole'];
        yield 'a round reviewer' => [ReviewRound::class, 'new \Intakt\Core\Review\Domain\RoundReviewer($id, $round, $member)', 'intakt.reviewerRole'];
        yield 'a conversation' => [SymfonyAiVendor::class, 'new \Intakt\Core\Review\Contract\ReviewerConversation($stopped->rolePrompt, $id)', 'intakt.reviewerConversation'];
    }

    #[DataProvider('traitBuilders')]
    public function testATraitBodyIsOutsideTheClassThatUsesIt(string $class, string $expression, string $identifier): void
    {
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $folder = 'src/'.str_replace('\\', '/', substr($namespace, strlen('Intakt\\')));
        $sandbox = $this->emptySandbox();
        $sandbox->write($folder.'/Builds.php', $this->traitBuilding($namespace, $expression));
        $sandbox->write($folder.'/'.substr($class, strlen($namespace) + 1).'.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfinal readonly class ".substr($class, strlen($namespace) + 1)."\n{\n    use Builds;\n}\n");

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertSame(1, substr_count($findings, $identifier), $findings);
        self::assertStringContainsString($folder.'/Builds.php:'.self::TRAIT_CASE_LINE.' '.$identifier, $findings);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusedTraitBuilders(): iterable
    {
        yield 'a role' => ['new \Intakt\Core\Review\Contract\ReviewerRole(\'x\', $text)', 'intakt.reviewerRole'];
        yield 'an imported round reviewer' => ['new RoundReviewer($id, $round, $member)', 'intakt.reviewerRole'];
        yield 'a conversation in another letter case' => ['new \Intakt\Core\Review\Contract\reviewerconversation($stopped->rolePrompt, $id)', 'intakt.reviewerConversation'];
        yield 'a role through its class constant' => ['new (\Intakt\Core\Review\Contract\ReviewerRole::class)(\'x\', $text)', 'intakt.reviewerRole'];
        yield 'a role through a class-name string' => ['new (\'Intakt\\Core\\Review\\Contract\\ReviewerRole\')(\'x\', $text)', 'intakt.reviewerRole'];
    }

    #[DataProvider('unusedTraitBuilders')]
    public function testATraitNoClassUsesIsJudgedWhereItIsDeclared(string $expression, string $identifier): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding('Intakt\Sample', $expression));

        self::assertStringContainsString('src/Sample/Builds.php:'.self::TRAIT_CASE_LINE.' '.$identifier, $this->ruleFindingsIn($sandbox));
    }

    public function testATraitMethodDoesNotTakeTheTypeOfAClassStringAssignedInItsFile(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Review/UseCase/RunReviewRound/Builds.php', "<?php\n\nnamespace Intakt\\Core\\Review\\UseCase\\RunReviewRound;\n\nuse ArrayObject;\nuse Intakt\\Core\\Review\\Contract\\ReviewerConversation;\nuse Symfony\\Component\\Uid\\UuidV7;\n\n\$class = ArrayObject::class;\n\ntrait Builds\n{\n    public function build(string \$class, string \$prompt, UuidV7 \$id): object\n    {\n        return new \$class(\$prompt, \$id);\n    }\n}\n");
        $sandbox->write(self::HANDLER, "<?php\n\nnamespace Intakt\\Core\\Review\\UseCase\\RunReviewRound;\n\nfinal class ReviewWithReviewerHandler\n{\n    use Builds;\n}\n");

        self::assertStringContainsString('src/Core/Review/UseCase/RunReviewRound/Builds.php (in context of class Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler):15 intakt.reviewerRole A trait builds an object whose class PHPStan cannot name', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function usedTraitRunTimeClasses(): iterable
    {
        yield 'a plain string' => ['new $text(\'x\', $text)'];
        yield 'a value of no known type' => ['new $anything()'];
        yield 'a known class or a plain string' => ['new (\'\' === $text ? \ArrayObject::class : $text)()'];
    }

    #[DataProvider('usedTraitRunTimeClasses')]
    public function testAUsedTraitBuildingAClassPhpStanCannotNameIsRefused(string $expression): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding('Intakt\Sample', $expression));
        $sandbox->write('src/Sample/Builder.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal class Builder\n{\n    use Builds;\n}\n");

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertSame(1, substr_count($findings, 'intakt.reviewerRole'), $findings);
        self::assertStringContainsString('src/Sample/Builds.php (in context of class Intakt\Sample\Builder):'.self::TRAIT_CASE_LINE.' intakt.reviewerRole A trait builds an object whose class PHPStan cannot name', $findings);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function usedTraitNamedOtherClasses(): iterable
    {
        yield 'one of two other class constants' => ['new ($text ? \ArrayObject::class : \stdClass::class)()'];
        yield 'a class string it assigns itself' => ['new ($text = \ArrayObject::class)()'];
        yield 'the using class' => ['new self()'];
        yield 'the using class at run time' => ['new static()'];
    }

    #[DataProvider('usedTraitNamedOtherClasses')]
    public function testAUsedTraitBuildingOnlyNamedOtherClassesPasses(string $expression): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding('Intakt\Sample', $expression));
        $sandbox->write('src/Sample/Builder.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal class Builder\n{\n    use Builds;\n}\n");

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testATraitCanInstantiateAnUnrelatedResolvedClass(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', "<?php\nnamespace Intakt\\Sample;\n\nuse ArrayObject;\nuse stdClass;\n\ntrait Builds\n{\n    public function build(bool \$wrapped): object\n    {\n        \$class = \$wrapped ? ArrayObject::class : stdClass::class;\n\n        return new \$class();\n    }\n}\n\nfinal class Builder\n{\n    use Builds;\n}\n");

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testATraitCannotBuildARoundReviewerThroughAnInterfaceBound(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builder.php', $this->interfaceBoundBuilder('Intakt\Sample', 'Builder', true));

        self::assertStringContainsString('src/Sample/Builder.php (in context of class Intakt\Sample\Builder):15 intakt.reviewerRole new RoundReviewer() outside', $this->ruleFindingsIn($sandbox));
    }

    public function testAClassCannotBuildARoundReviewerThroughAnInterfaceBound(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builder.php', $this->interfaceBoundBuilder('Intakt\Sample', 'Builder', false));

        self::assertStringContainsString('src/Sample/Builder.php:15 intakt.reviewerRole new RoundReviewer() outside', $this->ruleFindingsIn($sandbox));
    }

    public function testTheRoundMayBuildARoundReviewerThroughAnInterfaceBound(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Review/Domain/ReviewRound.php', $this->interfaceBoundBuilder('Intakt\Core\Review\Domain', 'ReviewRound', false));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testAnInterfaceBoundNoCarrierImplementsPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builder.php', "<?php\nnamespace Intakt\\Sample;\n\nuse Countable;\n\ntrait Builds\n{\n    /** @param class-string<Countable> \$class */\n    private function build(string \$class): Countable\n    {\n        return new \$class();\n    }\n}\n\nfinal class Builder\n{\n    use Builds;\n\n    public function list(): Countable\n    {\n        return \$this->build(\\ArrayObject::class);\n    }\n}\n");

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testAnIntersectionBoundNoCarrierSatisfiesPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Review/Domain/LocalFactory.php', "<?php\nnamespace Intakt\\Core\\Review\\Domain;\n\nuse Countable;\n\ntrait LocalFactory\n{\n    /** @param class-string<HasPlace&Countable> \$class */\n    private function build(string \$class): HasPlace&Countable\n    {\n        return new \$class();\n    }\n}\n\nfinal class FactoryUser\n{\n    use LocalFactory;\n\n    public function fixture(): HasPlace&Countable\n    {\n        return \$this->build(CountedPlace::class);\n    }\n}\n\nfinal class CountedPlace implements HasPlace, Countable\n{\n    public function place(): string { return ''; }\n    public function moveTo(string \$place): void {}\n    public function count(): int { return 0; }\n}\n");

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testAnIntersectionBoundWithAPropertyNoCarrierHasPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Fixture.php', "<?php\nnamespace Intakt\\Sample;\n\nuse Intakt\\Core\\Review\\Domain\\HasPlace;\n\nfinal class Fixture implements HasPlace\n{\n    public string \$label = 'fixture';\n\n    public function place(): string { return ''; }\n    public function moveTo(string \$place): void {}\n}\n\nfinal class Factory\n{\n    /** @param class-string<HasPlace&object{label: string}> \$class */\n    private function build(string \$class): HasPlace\n    {\n        return new \$class();\n    }\n\n    public function fixture(): HasPlace\n    {\n        return \$this->build(Fixture::class);\n    }\n}\n");

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testAShapeBoundThatCanNameAConversationIsRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Factory.php', "<?php\nnamespace Intakt\\Sample;\n\nuse Intakt\\Core\\Review\\Contract\\ReviewerConversation as Conversation;\nuse Symfony\\Component\\Uid\\Uuid;\n\nfinal class Factory\n{\n    /** @param class-string<object{rolePrompt: string}> \$class */\n    private function make(string \$class): object\n    {\n        return new \$class('fixture prompt', Uuid::v7());\n    }\n\n    public function conversation(): object\n    {\n        return \$this->make(Conversation::class);\n    }\n}\n");

        self::assertStringContainsString('src/Sample/Factory.php:12 intakt.reviewerConversation', $this->ruleFindingsIn($sandbox));
    }

    public function testAShapeBoundNoCarrierHasPasses(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Factory.php', "<?php\nnamespace Intakt\\Sample;\n\nfinal class Labeled\n{\n    public string \$label = 'fixture';\n}\n\nfinal class Factory\n{\n    /** @param class-string<object{label: string}> \$class */\n    private function make(string \$class): object\n    {\n        return new \$class();\n    }\n\n    public function labeled(): object\n    {\n        return \$this->make(Labeled::class);\n    }\n}\n");

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testAnIntersectionBoundACarrierSatisfiesIsRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builder.php', "<?php\nnamespace Intakt\\Sample;\n\nuse Intakt\\Core\\Review\\Domain\\HasPlace;\nuse Intakt\\Core\\Review\\Domain\\RoundReviewer;\n\nfinal class Builder\n{\n    /** @param class-string<HasPlace&RoundReviewer> \$class */\n    public function build(string \$class): HasPlace\n    {\n        return new \$class();\n    }\n}\n");

        self::assertStringContainsString('src/Sample/Builder.php:12 intakt.reviewerRole new RoundReviewer() outside', $this->ruleFindingsIn($sandbox));
    }

    public function testATraitATraitOfAClassUsesIsJudgedInThatClass(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding('Intakt\Sample', 'new $role(\'x\', $text)'));
        $sandbox->write('src/Sample/BuildsMore.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\ntrait BuildsMore\n{\n    use Builds;\n}\n");
        $sandbox->write('src/Sample/Builder.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal class Builder\n{\n    use BuildsMore;\n}\n");

        self::assertStringContainsString('src/Sample/Builds.php (in context of class Intakt\Sample\Builder):'.self::TRAIT_CASE_LINE.' intakt.reviewerRole new ReviewerRole() outside', $this->ruleFindingsIn($sandbox));
    }

    public function testATraitNoClassUsesIsLeftToTheUnusedTraitRuleForAClassNamedAtRunTime(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding('Intakt\Sample', 'new $text(\'x\', $text)'));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testATraitMethodBuildingFromItsOwnTypedClassStringIsRefusedAsThatCarrier(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Review/UseCase/RunReviewRound/Builds.php', "<?php\n\nnamespace Intakt\\Core\\Review\\UseCase\\RunReviewRound;\n\nuse ArrayObject;\nuse Intakt\\Core\\Review\\Contract\\ReviewerConversation;\nuse Symfony\\Component\\Uid\\UuidV7;\n\n\$class = ArrayObject::class;\n\ntrait Builds\n{\n    /** @param class-string<ReviewerConversation> \$class */\n    public function build(string \$class, string \$prompt, UuidV7 \$id): ReviewerConversation\n    {\n        return new \$class(\$prompt, \$id);\n    }\n}\n");
        $sandbox->write(self::HANDLER, "<?php\n\nnamespace Intakt\\Core\\Review\\UseCase\\RunReviewRound;\n\nfinal class ReviewWithReviewerHandler\n{\n    use Builds;\n}\n");

        self::assertStringContainsString('src/Core/Review/UseCase/RunReviewRound/Builds.php (in context of class Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler):16 intakt.reviewerConversation', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traitConstructionsOfOtherClasses(): iterable
    {
        yield 'a named class' => ['new \Intakt\Core\Review\Contract\PanelReviewer($stopped->rolePrompt, $id, 1)'];
        yield 'a class constant' => ['new (\ArrayObject::class)()'];
        yield 'an anonymous class' => ['new class () {}'];
    }

    #[DataProvider('traitConstructionsOfOtherClasses')]
    public function testATraitThatBuildsAnotherNamedClassPasses(string $expression): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Builds.php', $this->traitBuilding('Intakt\Sample', $expression));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function carrierUnions(): iterable
    {
        yield 'a conversation behind a role in the panel configuration' => [ConfiguredPanels::class, 'new (\'\' === $text ? ReviewerRole::class : ReviewerConversation::class)(\'x\', $text)', 'intakt.reviewerConversation'];
        yield 'a role behind a conversation in the handler' => [ReviewWithReviewerHandler::class, 'new (\'\' === $text ? ReviewerConversation::class : ReviewerRole::class)($reviewer->rolePrompt(), $id)', 'intakt.reviewerRole'];
        yield 'a round reviewer behind a role in the sample project' => [SampleProject::class, 'new (\'\' === $text ? ReviewerRole::class : RoundReviewer::class)($id, $round, $member)', 'intakt.reviewerRole'];
    }

    #[DataProvider('carrierUnions')]
    public function testEveryClassAClassStringUnionNamesIsJudged(string $class, string $expression, string $identifier): void
    {
        $path = 'src/'.str_replace('\\', '/', substr($class, strlen('Intakt\\'))).'.php';
        $sandbox = $this->emptySandbox();
        $sandbox->write($path, $this->classWithCases($class, self::BUILDER_PARAMETERS, [$expression]));

        self::assertStringContainsString($path.':'.self::FIRST_CASE_LINE.' '.$identifier, $this->ruleFindingsIn($sandbox));
    }

    public function testAnUnprovenRolePromptBehindAnAllowedCarrierIsRefused(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write(self::HANDLER, $this->classWithCases(ReviewWithReviewerHandler::class, self::BUILDER_PARAMETERS, ['new (\'\' === $text ? ReviewerConversation::class : ReviewerConversation::class)($text, $id)']));

        self::assertStringContainsString(self::HANDLER.':'.self::FIRST_CASE_LINE.' intakt.unprovenRolePrompt', $this->ruleFindingsIn($sandbox));
    }

    public function testRolesRoundReviewersAndConversationsBuiltUnderTestsAreNotJudged(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/Sample/ReportTest.php', $this->classWithCases('Intakt\Tests\Sample\ReportTest', self::BUILDER_PARAMETERS, ['new ReviewerRole(\'x\', $text)', 'new RoundReviewer($id, $round, $member)', 'new ReviewerConversation($text, $id)']));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function builderAdvice(): iterable
    {
        yield 'a role' => ['new ReviewerRole(\'x\', $text)', 'new ReviewerRole() outside ConfiguredPanels and SampleProject: a role prompt enters the system message verbatim, so build a ReviewerRole only from the owner\'s panel configuration (ConfiguredPanels) or as a fixed sample role (SampleProject).'];
        yield 'a round reviewer' => ['new RoundReviewer($id, $round, $member)', 'new RoundReviewer() outside ReviewRound: a round reviewer carries its panel member\'s role prompt, so let ReviewRound build it from a PanelReviewer of the round\'s panel.'];
        yield 'a conversation' => ['new ReviewerConversation($text, $id)', 'new ReviewerConversation() outside ReviewWithReviewerHandler and SymfonyAiVendor: a conversation carries the role prompt PromptAssembly::systemWithRole() puts into the system message, so start it in ReviewWithReviewerHandler from $reviewer->rolePrompt(), or copy an existing conversation\'s rolePrompt in SymfonyAiVendor\'s retry.'];
    }

    #[DataProvider('builderAdvice')]
    public function testTheFindingSaysWhereTheObjectMayBeBuilt(string $expression, string $advice): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Sample/Report.php', $this->classWithCases('Intakt\Sample\Report', self::BUILDER_PARAMETERS, [$expression]));

        self::assertStringContainsString($advice, $this->ruleFindingsIn($sandbox));
    }

    protected function ruleFor(string $root): RawPromptMessageRule
    {
        return new RawPromptMessageRule($root);
    }

    private function interfaceBoundBuilder(string $namespace, string $class, bool $inTrait): string
    {
        $build = "    /** @param class-string<HasPlace> \$class */\n    private function build(string \$class, UuidV7 \$id, \\Intakt\\Core\\Review\\Domain\\ReviewRound \$round, PanelReviewer \$member): HasPlace\n    {\n        return new \$class(\$id, \$round, \$member);\n    }\n";
        $reviewer = "    public function reviewer(UuidV7 \$id, \\Intakt\\Core\\Review\\Domain\\ReviewRound \$round, PanelReviewer \$member): HasPlace\n    {\n        return \$this->build(RoundReviewer::class, \$id, \$round, \$member);\n    }\n";
        $declarations = $inTrait ? "trait Builds\n{\n{$build}}\n\nfinal class {$class}\n{\n    use Builds;\n\n{$reviewer}}\n" : "final class {$class}\n{\n{$build}\n{$reviewer}}\n";

        return "<?php\nnamespace {$namespace};\n\nuse Intakt\\Core\\Review\\Contract\\PanelReviewer;\nuse Intakt\\Core\\Review\\Domain\\HasPlace;\nuse Intakt\\Core\\Review\\Domain\\RoundReviewer;\nuse Symfony\\Component\\Uid\\UuidV7;\n\n\n{$declarations}";
    }

    private function traitBuilding(string $namespace, string $expression): string
    {
        $parameters = 'string $text, \Symfony\Component\Uid\UuidV7 $id, \Intakt\Core\Review\Domain\ReviewRound $round, \Intakt\Core\Review\Contract\PanelReviewer $member, \Intakt\Core\Review\Contract\ReviewerConversation $stopped, string $role';

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nuse Intakt\\Core\\Review\\Domain\\RoundReviewer;\n\ntrait Builds\n{\n    /** @param class-string<\\Intakt\\Core\\Review\\Contract\\ReviewerRole> \$role */\n    public function build({$parameters}): mixed\n    {\n        return {$expression};\n    }\n}\n";
    }

    /**
     * @param list<string> $expressions
     */
    private function handlerWithCases(array $expressions): string
    {
        $parameters = '(RoundReviewer $reviewer, ?RoundReviewer $maybe, ReviewerConversation $conversation, string $text, UuidV7 $id, array $arguments)';

        return $this->classWithCases(ReviewWithReviewerHandler::class, $parameters, $expressions);
    }

    /**
     * @param list<string> $expressions
     */
    private function classWithCases(string $class, string $parameters, array $expressions): string
    {
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $shortName = substr($class, strlen($namespace) + 1);
        $imports = self::IMPORTS;
        $methods = array_map(
            static fn (int $index, string $expression): string => "    public function case{$index}{$parameters}: mixed\n    {\n        return {$expression};\n    }\n",
            array_keys($expressions),
            $expressions,
        );

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n{$imports}\nfinal readonly class {$shortName}\n{\n".implode("\n", $methods)."}\n";
    }
}
