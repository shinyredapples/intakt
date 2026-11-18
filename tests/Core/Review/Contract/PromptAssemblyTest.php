<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\FencedText;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\UntrustedSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\AI\Platform\Message\Role;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(PromptAssembly::class)]
#[UsesClass(FencedText::class)]
#[UsesClass(ReviewerConversation::class)]
final class PromptAssemblyTest extends TestCase
{
    private const string INSTRUCTIONS = 'You review one change.';

    public function testAUserMessageIsTheLeadABlankLineAndTheFencedText(): void
    {
        $fenced = new FencedText(UntrustedSource::Task, 'Add a cart.');

        $message = new PromptAssembly()->user('The task of the change:', $fenced);

        self::assertSame(Role::User, $message->getRole());
        self::assertSame("The task of the change:\n\n".$fenced->rendered(), $message->asText());
    }

    public function testTheTextOfAUserMessageIsWhatTheMessageCarries(): void
    {
        $fenced = new FencedText(UntrustedSource::Diff, 'diff')->cut(4, 5);

        self::assertSame(new PromptAssembly()->user('The diff:', $fenced)->asText(), new PromptAssembly()->userText('The diff:', $fenced));
    }

    public function testASystemMessageHoldsOnlyTheInstructions(): void
    {
        $message = new PromptAssembly()->system(self::INSTRUCTIONS);

        self::assertSame(Role::System, $message->getRole());
        self::assertSame(self::INSTRUCTIONS, $message->getContent());
    }

    public function testASystemMessageWithARoleHoldsTheInstructionsThenTheRolePromptOfTheConversation(): void
    {
        $conversation = new ReviewerConversation('Look at security.', new UuidV7());

        $message = new PromptAssembly()->systemWithRole(self::INSTRUCTIONS, $conversation);

        self::assertSame(self::INSTRUCTIONS."\n\nLook at security.", $message->getContent());
    }

    public function testTheRolePromptOfASystemMessageIsTakenOnlyFromAReviewerConversationNeverFromAString(): void
    {
        $role = new ReflectionMethod(PromptAssembly::class, 'systemWithRole')->getParameters()[1]->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $role);
        self::assertSame(ReviewerConversation::class, $role->getName());
    }

    public function testANoticeIsAUserMessageOfIntaktsOwnTextAlone(): void
    {
        $message = new PromptAssembly()->notice('Name your findings or approve.');

        self::assertSame(Role::User, $message->getRole());
        self::assertSame('Name your findings or approve.', $message->asText());
    }

    public function testAToolAnswerIsTheRenderingOfTheFencedText(): void
    {
        $fenced = new FencedText(UntrustedSource::ToolResult, 'final class Cart {}')->cut(19, 20);

        self::assertSame($fenced->rendered(), new PromptAssembly()->toolAnswer($fenced));
    }
}
