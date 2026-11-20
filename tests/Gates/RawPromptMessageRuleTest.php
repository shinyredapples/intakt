<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Gates\PhpStan\RawPromptMessageRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @extends PhpStanRuleTestCase<RawPromptMessageRule>
 */
#[CoversClass(RawPromptMessageRule::class)]
final class RawPromptMessageRuleTest extends PhpStanRuleTestCase
{
    private const string MESSAGE_IMPORTS = "use Symfony\\AI\\Platform\\Message\\Content\\Text;\nuse Symfony\\AI\\Platform\\Message\\Message;\nuse Symfony\\AI\\Platform\\Message\\SystemMessage;\nuse Symfony\\AI\\Platform\\Message\\UserMessage;\n";
    private const string IMPORTS = self::MESSAGE_IMPORTS."use Intakt\\Core\\Review\\Contract\\ReviewerConversation;\nuse Intakt\\Core\\Review\\Domain\\RoundReviewer;\nuse Symfony\\Component\\Uid\\UuidV7;\n";
    private const string HANDLER = 'src/Core/Review/UseCase/RunReviewRound/ReviewWithReviewerHandler.php';
    private const int FIRST_CASE_LINE = 19;

    protected function ruleFor(string $root): RawPromptMessageRule
    {
        return new RawPromptMessageRule($root);
    }

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
        $sandbox->write('src/Sample/Briefing.php', $this->classWithCases('Intakt\Sample\Briefing', '(string $task, \Intakt\Core\Review\Contract\PromptAssembly $assembly, \Intakt\Core\Review\Contract\Fence $fence)', [
            "\$assembly->user('The task:', \$fence->wrap(\\Intakt\\Core\\Review\\Contract\\UntrustedSource::Task, \$task))",
            "\$assembly->notice('Answer with the single word: ok')",
            "\$assembly->systemWithRole('You review.', new ReviewerConversation(\$task, \\Symfony\\Component\\Uid\\Uuid::v7()))",
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

    public function testAConversationOutsideTheHandlerIsNotJudgedByThisRule(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Plugin/Sample/Retry.php', $this->classWithCases('Intakt\Plugin\Sample\\Retry', '(string $text, UuidV7 $id)', ['new ReviewerConversation($text, $id)']));

        self::assertSame('', $this->ruleFindingsIn($sandbox));
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
