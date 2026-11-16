<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use ArrayObject;
use Closure;
use Intakt\Core\Ai\Contract\KeyKind;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiKeyCheck;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(SymfonyAiKeyCheck::class)]
#[UsesClass(ProviderKey::class)]
#[UsesClass(KeyKind::class)]
final class SymfonyAiKeyCheckTest extends TestCase
{
    private const string BODY = 'abcdefgh12345678';

    private const array ANTHROPIC_ANSWER = [
        'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
        'content' => [['type' => 'text', 'text' => 'ok']],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ];

    private const array OPENAI_ANSWER = [
        'id' => 'resp_test', 'object' => 'response', 'status' => 'completed', 'model' => 'gpt-4.1-nano',
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'ok']]]],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
    ];

    private const array CHAT_COMPLETION_ANSWER = [
        'id' => 'chatcmpl_test', 'object' => 'chat.completion', 'created' => 1, 'model' => 'meta-llama/Llama-3.2-1B-Instruct',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function keys(): iterable
    {
        yield 'anthropic' => ['sk-ant-'.self::BODY];
        yield 'openai' => ['sk-'.self::BODY];
        yield 'hugging face' => ['hf_'.self::BODY];
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function answers(): iterable
    {
        yield 'anthropic' => ['sk-ant-'.self::BODY, self::ANTHROPIC_ANSWER];
        yield 'openai' => ['sk-'.self::BODY, self::OPENAI_ANSWER];
        yield 'hugging face' => ['hf_'.self::BODY, self::CHAT_COMPLETION_ANSWER];
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function endpoints(): iterable
    {
        yield 'anthropic' => ['sk-ant-'.self::BODY, 'https://api.anthropic.com/v1/messages', self::ANTHROPIC_ANSWER];
        yield 'openai' => ['sk-'.self::BODY, 'https://api.openai.com/v1/responses', self::OPENAI_ANSWER];
        yield 'hugging face' => ['hf_'.self::BODY, 'https://router.huggingface.co/v1/chat/completions', self::CHAT_COMPLETION_ANSWER];
    }

    /**
     * @return iterable<string, array{string, string, string, int, array<string, mixed>}>
     */
    public static function requests(): iterable
    {
        yield 'anthropic' => ['sk-ant-'.self::BODY, 'claude-haiku-5-5', 'max_tokens', 1, self::ANTHROPIC_ANSWER];
        yield 'openai' => ['sk-'.self::BODY, 'gpt-4.1-nano', 'max_output_tokens', 16, self::OPENAI_ANSWER];
        yield 'hugging face' => ['hf_'.self::BODY, 'meta-llama/Llama-3.2-1B-Instruct', 'max_tokens', 1, self::CHAT_COMPLETION_ANSWER];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('endpoints')]
    public function testEachKindIsCheckedWithOneCallToItsOwnProvider(string $key, string $url, array $answer): void
    {
        $calls = new ArrayObject();

        $this->check($key, $calls, static fn (): MockResponse => self::answer(200, $answer));

        self::assertSame([['POST', $url]], array_map(static fn (array $call): array => [$call[0], $call[1]], $calls->getArrayCopy()));
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('requests')]
    public function testEachKindAsksTheCheapestModelForTheShortestAnswerItsProviderAllows(string $key, string $model, string $capField, int $cap, array $answer): void
    {
        $calls = new ArrayObject();

        $this->check($key, $calls, static fn (): MockResponse => self::answer(200, $answer));

        $request = $calls[0][2];
        self::assertSame($model, $request['model']);
        self::assertSame($cap, $request[$capField]);
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('answers')]
    public function testAKeyItsProviderAnswersIsAccepted(string $key, array $answer): void
    {
        $outcome = $this->check($key, new ArrayObject(), static fn (): MockResponse => self::answer(200, $answer));

        self::assertSame(KeyCheckOutcome::Accepted, $outcome);
    }

    #[DataProvider('keys')]
    public function testAKeyItsProviderAnswersWithUnauthorizedIsRefused(string $key): void
    {
        $outcome = $this->check($key, new ArrayObject(), static fn (): MockResponse => self::answer(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'Invalid key '.$key]]));

        self::assertSame(KeyCheckOutcome::Refused, $outcome);
    }

    #[DataProvider('keys')]
    public function testAProviderServerErrorLeavesTheKeyNotChecked(string $key): void
    {
        $outcome = $this->check($key, new ArrayObject(), static fn (): MockResponse => self::answer(500, ['error' => ['message' => 'overloaded']]));

        self::assertSame(KeyCheckOutcome::NotChecked, $outcome);
    }

    public function testAnUnreachableProviderLeavesTheKeyNotChecked(): void
    {
        $outcome = $this->check('sk-ant-'.self::BODY, new ArrayObject(), static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused']));

        self::assertSame(KeyCheckOutcome::NotChecked, $outcome);
    }

    public function testAGoodOpenAiKeyIsAcceptedByAProviderThatRefusesAnAnswerCapBelowSixteenTokens(): void
    {
        $outcome = $this->check('sk-'.self::BODY, new ArrayObject(), static fn (array $request): MockResponse => $request['max_output_tokens'] < 16
            ? self::answer(400, ['error' => ['type' => 'invalid_request_error', 'param' => 'max_output_tokens', 'code' => 'integer_below_min_value', 'message' => 'Expected a value >= 16.']])
            : self::answer(200, self::OPENAI_ANSWER));

        self::assertSame(KeyCheckOutcome::Accepted, $outcome);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function answer(int $status, array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }

    /**
     * @param ArrayObject<int, array{string, string, array<string, mixed>}> $calls
     * @param Closure(array<string, mixed>): MockResponse                   $answer
     */
    private function check(string $key, ArrayObject $calls, Closure $answer): KeyCheckOutcome
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($calls, $answer): MockResponse {
            $request = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            $calls->append([$method, $url, $request]);

            return $answer($request);
        });

        return new SymfonyAiKeyCheck($http)->check(ProviderKey::recognise($key));
    }
}
