<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Review\Infrastructure\ReviewJobAnswer;
use Intakt\Core\Review\Infrastructure\ReviewJobFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewJobAnswer::class)]
#[CoversClass(ReviewJobFailure::class)]
final class ReviewJobAnswerTest extends TestCase
{
    public function testClaudeCodesResultIsTheAnswer(): void
    {
        $answer = ReviewJobAnswer::read(Provider::ClaudeCode, '{"type":"result","subtype":"success","is_error":false,"result":"looks fine","usage":{}}');

        self::assertSame('looks fine', $answer->text);
    }

    public function testCodexsLastAgentMessageOfACompletedTurnIsTheAnswer(): void
    {
        $answer = ReviewJobAnswer::read(Provider::Codex, implode("\n", [
            '{"type":"thread.started","thread_id":"t"}',
            '{"type":"item.completed","item":{"id":"1","type":"agent_message","text":"first"}}',
            '{"type":"item.completed","item":{"id":"2","type":"reasoning","text":"thinking"}}',
            '{"type":"item.completed","item":{"id":"3","type":"agent_message","text":"last"}}',
            '{"type":"turn.completed","usage":{"input_tokens":1}}',
            '',
        ]));

        self::assertSame('last', $answer->text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableClaudeCodeAnswers(): iterable
    {
        yield 'no answer' => [''];
        yield 'not JSON' => ['looks fine'];
        yield 'a list' => ['["looks fine"]'];
        yield 'an error' => ['{"type":"result","is_error":true,"result":"rate limited"}'];
        yield 'no error flag' => ['{"type":"result","result":"looks fine"}'];
        yield 'another type' => ['{"type":"assistant","is_error":false,"result":"looks fine"}'];
        yield 'a result that is no text' => ['{"type":"result","is_error":false,"result":{"text":"looks fine"}}'];
        yield 'an empty result' => ['{"type":"result","is_error":false,"result":""}'];
    }

    #[DataProvider('unreadableClaudeCodeAnswers')]
    public function testAClaudeCodeAnswerThatIsNoSuccessfulResultIsRefused(string $output): void
    {
        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessage('The reviewer\'s CLI claude_code gave no answer the review job can read.');

        ReviewJobAnswer::read(Provider::ClaudeCode, $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableCodexAnswers(): iterable
    {
        $message = '{"type":"item.completed","item":{"type":"agent_message","text":"last"}}';
        $completed = '{"type":"turn.completed","usage":{}}';

        yield 'no answer' => [''];
        yield 'a turn that never completed' => [$message."\n"];
        yield 'a completed turn without a message' => [$completed."\n"];
        yield 'a line that is not JSON' => [$message."\nnoise\n".$completed."\n"];
        yield 'a line that is no object' => [$message."\n3\n".$completed."\n"];
        yield 'a message that is no text' => ['{"type":"item.completed","item":{"type":"agent_message","text":["last"]}}'."\n".$completed."\n"];
        yield 'an empty message' => ['{"type":"item.completed","item":{"type":"agent_message","text":""}}'."\n".$completed."\n"];
        yield 'a failed turn after the message' => [$message."\n".'{"type":"turn.failed","error":{"message":"gone"}}'."\n"];
    }

    #[DataProvider('unreadableCodexAnswers')]
    public function testACodexAnswerThatIsNoCompletedTurnWithAMessageIsRefused(string $output): void
    {
        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessage('The reviewer\'s CLI codex gave no answer the review job can read.');

        ReviewJobAnswer::read(Provider::Codex, $output);
    }

    public function testAnAnswerOfAProviderWithoutAReviewerCliIsRefused(): void
    {
        $this->expectException(ReviewJobFailure::class);

        ReviewJobAnswer::read(Provider::Anthropic, '{"type":"result","is_error":false,"result":"looks fine"}');
    }
}
