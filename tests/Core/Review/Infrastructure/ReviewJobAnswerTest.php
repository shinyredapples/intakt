<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Review\Infrastructure\ClaudeCodeJobEvents;
use Intakt\Core\Review\Infrastructure\ReportedUsage;
use Intakt\Core\Review\Infrastructure\ReviewJob;
use Intakt\Core\Review\Infrastructure\ReviewJobAnswer;
use Intakt\Core\Review\Infrastructure\ReviewJobFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewJobAnswer::class)]
#[CoversClass(ClaudeCodeJobEvents::class)]
#[CoversClass(ReportedUsage::class)]
#[CoversClass(ReviewJobFailure::class)]
#[UsesClass(ReviewJob::class)]
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
        $this->expectExceptionMessageIsOrContains('The reviewer\'s CLI claude_code gave no answer the review job can read.');

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

        $started = '{"type":"turn.started"}';
        $earlier = $started."\n".'{"type":"item.completed","item":{"type":"agent_message","text":"earlier"}}'."\n".$completed."\n";

        yield 'a later failed turn holding the last message' => [$earlier.$started."\n".$message."\n".'{"type":"turn.failed","error":{"message":"stream interrupted"}}'."\n"];
        yield 'a later turn holding the last message that never ended' => [$earlier.$started."\n".$message."\n"];
        yield 'a later completed turn after the unfinished turn holding the last message' => [$started."\n".$message."\n".$started."\n".$completed."\n"];
    }

    public function testCodexsAnswerOfACompletedTurnStandsWhenALaterTurnWithoutAMessageFails(): void
    {
        $answer = ReviewJobAnswer::read(Provider::Codex, implode("\n", [
            '{"type":"turn.started"}',
            '{"type":"item.completed","item":{"type":"agent_message","text":"answer"}}',
            '{"type":"turn.completed","usage":{}}',
            '{"type":"turn.started"}',
            '{"type":"turn.failed","error":{"message":"gone"}}',
        ]));

        self::assertSame('answer', $answer->text);
    }

    #[DataProvider('unreadableCodexAnswers')]
    public function testACodexAnswerThatIsNoCompletedTurnWithAMessageIsRefused(string $output): void
    {
        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessageIsOrContains('The reviewer\'s CLI codex gave no answer the review job can read.');

        ReviewJobAnswer::read(Provider::Codex, $output);
    }

    public function testAnAnswerOfAProviderWithoutAReviewerCliIsRefused(): void
    {
        $this->expectException(ReviewJobFailure::class);

        ReviewJobAnswer::read(Provider::Anthropic, '{"type":"result","is_error":false,"result":"looks fine"}');
    }

    public function testAClaudeCodeStreamWithASuccessfulReadOfACheckoutFileShowsTheRead(): void
    {
        $answer = ReviewJobAnswer::read(Provider::ClaudeCode, $this->claudeStream(self::read('toolu_1', ReviewJob::CHECKOUT.'/src/Cart.php'), self::readResult('toolu_1', false)));

        self::assertTrue($answer->readTheCheckout);
        self::assertSame('looks fine', $answer->text);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function streamsWithoutARead(): iterable
    {
        yield 'no tool call' => [[]];
        yield 'a read that failed' => [[self::read('toolu_1', ReviewJob::CHECKOUT.'/src/Gone.php'), self::readResult('toolu_1', true)]];
        yield 'a read without a result' => [[self::read('toolu_1', ReviewJob::CHECKOUT.'/src/Cart.php')]];
        yield 'a read outside the checkout' => [[self::read('toolu_1', '/etc/hostname'), self::readResult('toolu_1', false)]];
        yield 'a read that steps out of the checkout' => [[self::read('toolu_1', ReviewJob::CHECKOUT.'/../etc/hostname'), self::readResult('toolu_1', false)]];
        yield 'a read whose error flag is no boolean' => [[self::read('toolu_1', ReviewJob::CHECKOUT.'/src/Cart.php'), ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'is_error' => 'true']]]]]];
        yield 'a read of git\'s metadata' => [[self::read('toolu_1', ReviewJob::CHECKOUT.'/.git/config'), self::readResult('toolu_1', false)]];
        yield 'a read of git\'s metadata in a dotted path' => [[self::read('toolu_1', ReviewJob::CHECKOUT.'/./.git/HEAD'), self::readResult('toolu_1', false)]];
        yield 'a result for another call' => [[self::read('toolu_1', ReviewJob::CHECKOUT.'/src/Cart.php'), self::readResult('toolu_2', false)]];
        yield 'a search only' => [[
            ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Grep', 'input' => ['pattern' => 'Cart', 'path' => ReviewJob::CHECKOUT]]]]],
            ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'src/Cart.php']]]],
        ]];
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    #[DataProvider('streamsWithoutARead')]
    public function testAClaudeCodeStreamWithoutASuccessfulReadOfACheckoutFileShowsNoRead(array $events): void
    {
        self::assertFalse(ReviewJobAnswer::read(Provider::ClaudeCode, $this->claudeStream(...$events))->readTheCheckout);
    }

    public function testTheModelsTextClaimingAReadShowsNoRead(): void
    {
        $output = (string) json_encode(['type' => 'result', 'is_error' => false, 'result' => 'I used Read on '.ReviewJob::CHECKOUT.'/src/Cart.php and it looks fine']);

        self::assertFalse(ReviewJobAnswer::read(Provider::ClaudeCode, $output)->readTheCheckout);
    }

    public function testClaudeCodesUsageIsReadFromItsFinalResult(): void
    {
        $output = (string) json_encode(['type' => 'result', 'is_error' => false, 'result' => 'fine', 'usage' => ['input_tokens' => 4, 'cache_creation_input_tokens' => 1500, 'cache_read_input_tokens' => 38000, 'output_tokens' => 700]]);

        $usage = ReviewJobAnswer::read(Provider::ClaudeCode, $output)->usage;

        self::assertCount(1, $usage);
        self::assertSame([4, 700, 39500], [$usage[0]->getPromptTokens(), $usage[0]->getCompletionTokens(), $usage[0]->getCachedTokens()]);
    }

    public function testAnEmptyUsageBlockCountsAsNoUsageReported(): void
    {
        self::assertSame([], ReviewJobAnswer::read(Provider::ClaudeCode, '{"type":"result","is_error":false,"result":"fine","usage":{}}')->usage);
    }

    /**
     * @return iterable<string, array{Provider, string}>
     */
    public static function invalidUsage(): iterable
    {
        $claudeCode = static fn (string $usage): string => '{"type":"result","is_error":false,"result":"fine","usage":'.$usage.'}';
        $codex = static fn (string $usage): string => '{"type":"item.completed","item":{"type":"agent_message","text":"fine"}}'."\n".'{"type":"turn.completed","usage":'.$usage.'}';

        yield 'a negative count of Claude Code' => [Provider::ClaudeCode, $claudeCode('{"input_tokens":-1,"output_tokens":2}')];
        yield 'a count of Claude Code that is no number' => [Provider::ClaudeCode, $claudeCode('{"input_tokens":[]}')];
        yield 'a cache count of Claude Code that is a fraction' => [Provider::ClaudeCode, $claudeCode('{"input_tokens":1,"cache_read_input_tokens":1.5}')];
        yield 'a negative cached count of Codex' => [Provider::Codex, $codex('{"input_tokens":1,"cached_input_tokens":-5,"output_tokens":2}')];
        yield 'a count of Codex that is text' => [Provider::Codex, $codex('{"input_tokens":"12"}')];
    }

    #[DataProvider('invalidUsage')]
    public function testAUsageWithACountThatIsNoWholeNumberOfZeroOrMoreCountsAsNoUsageReported(Provider $provider, string $output): void
    {
        self::assertSame([], ReviewJobAnswer::read($provider, $output)->usage);
    }

    public function testEveryCompletedCodexTurnHandsOnItsUsageAndNoRead(): void
    {
        $output = implode("\n", [
            '{"type":"turn.started"}',
            '{"type":"item.completed","item":{"type":"agent_message","text":"first"}}',
            '{"type":"turn.completed","usage":{"input_tokens":1200,"cached_input_tokens":800,"output_tokens":50}}',
            '{"type":"turn.started"}',
            '{"type":"item.completed","item":{"type":"agent_message","text":"answer"}}',
            '{"type":"turn.completed","usage":{"input_tokens":1300,"cached_input_tokens":0,"output_tokens":60}}',
        ])."\n";

        $answer = ReviewJobAnswer::read(Provider::Codex, $output);

        self::assertSame([[1200, 50, 800], [1300, 60, 0]], array_map(static fn ($usage): array => [$usage->getPromptTokens(), $usage->getCompletionTokens(), $usage->getCachedTokens()], $answer->usage));
        self::assertFalse($answer->readTheCheckout);
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $id, string $path): array
    {
        return ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => $id, 'name' => 'Read', 'input' => ['file_path' => $path]]]]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function readResult(string $id, bool $error): array
    {
        return ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $error ? 'File does not exist.' : "1\t<?php", 'is_error' => $error]]]];
    }

    /**
     * @param array<string, mixed> ...$events
     */
    private function claudeStream(array ...$events): string
    {
        $result = ['type' => 'result', 'is_error' => false, 'result' => 'looks fine'];

        return implode("\n", array_map(static fn (array $event): string => (string) json_encode($event), [...array_values($events), $result]))."\n";
    }
}
