<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use ArrayObject;
use Closure;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\SampleProjectData;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnthropicDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\HuggingFaceDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\OpenAiDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiKeyCheck;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProvider;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Uri\Rfc3986\Uri;

#[CoversClass(SymfonyAiKeyCheck::class)]
#[CoversClass(AnthropicDescriptor::class)]
#[CoversClass(OpenAiDescriptor::class)]
#[CoversClass(HuggingFaceDescriptor::class)]
#[UsesClass(ProviderKey::class)]
#[UsesClass(SymfonyAiProviderCatalog::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Provider::class)]
#[UsesClass(TierNotMapped::class)]
#[UsesClass(SampleProjectData::class)]
#[UsesClass(PromptAssembly::class)]
final class SymfonyAiKeyCheckTest extends TestCase
{
    private const string BODY = 'abcdefgh12345678';

    private const array ANTHROPIC_ANSWER = [
        'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'light-anthropic',
        'content' => [['type' => 'text', 'text' => 'ok']],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ];

    private const array OPENAI_ANSWER = [
        'id' => 'resp_test', 'object' => 'response', 'status' => 'completed', 'model' => 'light-openai',
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'ok']]]],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
    ];

    private const array CHAT_COMPLETION_ANSWER = [
        'id' => 'chatcmpl_test', 'object' => 'chat.completion', 'created' => 1, 'model' => 'light-openai_compatible',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ];

    private BufferingLogger $log;

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
    public static function paths(): iterable
    {
        yield 'anthropic' => ['sk-ant-'.self::BODY, '/v1/messages', self::ANTHROPIC_ANSWER];
        yield 'openai' => ['sk-'.self::BODY, '/v1/responses', self::OPENAI_ANSWER];
        yield 'hugging face' => ['hf_'.self::BODY, '/v1/chat/completions', self::CHAT_COMPLETION_ANSWER];
    }

    /**
     * @return iterable<string, array{string, array<string, int|string>, array<string, mixed>}>
     */
    public static function requests(): iterable
    {
        yield 'anthropic' => ['sk-ant-'.self::BODY, ['model' => 'light-anthropic', 'max_tokens' => 1], self::ANTHROPIC_ANSWER];
        yield 'openai' => ['sk-'.self::BODY, ['model' => 'light-openai', 'max_output_tokens' => 16], self::OPENAI_ANSWER];
        yield 'hugging face' => ['hf_'.self::BODY, ['model' => 'light-openai_compatible', 'max_tokens' => 1], self::CHAT_COMPLETION_ANSWER];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('paths')]
    public function testEachProviderIsCheckedWithOneCallToItsDescriptorsBaseUrl(string $key, string $path, array $answer): void
    {
        $providers = new SymfonyAiProviderCatalog();
        $baseUrl = $providers->descriptor(ProviderKey::recognise($key, $providers)->provider())?->baseUrl()->toString();

        $calls = $this->callsMadeFor($key, $answer);

        self::assertSame([['POST', $baseUrl.$path]], array_map(static fn (array $call): array => [$call[0], $call[1]], $calls));
    }

    /**
     * @param array<string, int|string> $expected
     * @param array<string, mixed>      $answer
     */
    #[DataProvider('requests')]
    public function testEachProviderAsksItsLightTierModelForItsDescriptorsSmallestAnswer(string $key, array $expected, array $answer): void
    {
        $calls = $this->callsMadeFor($key, $answer);

        self::assertCount(1, $calls);
        $request = $calls[0][2];
        self::assertSame($expected, array_map(static fn (string $field): mixed => $request[$field] ?? null, array_combine(array_keys($expected), array_keys($expected))));
    }

    /**
     * @param array<string, int|string> $expected
     * @param array<string, mixed>      $answer
     */
    #[DataProvider('requests')]
    public function testEachProviderIsAskedOnlyTheFixedCheckPrompt(string $key, array $expected, array $answer): void
    {
        $calls = $this->callsMadeFor($key, $answer);

        self::assertCount(1, $calls);
        $body = json_encode($calls[0][2], JSON_THROW_ON_ERROR);
        self::assertSame(1, substr_count($body, '"role":"user"'));
        self::assertSame(1, substr_count($body, '"Answer with the single word: ok"'));
        self::assertStringNotContainsString('"system"', $body);
    }

    /**
     * @return iterable<string, array{SymfonyAiProvider, string, array<string, mixed>}>
     */
    public static function movedEndpoints(): iterable
    {
        yield 'anthropic' => [new AnthropicDescriptor(new Uri('https://gateway.example')), 'sk-ant-'.self::BODY, self::ANTHROPIC_ANSWER];
        yield 'openai' => [new OpenAiDescriptor(new Uri('https://gateway.example')), 'sk-'.self::BODY, self::OPENAI_ANSWER];
        yield 'hugging face' => [new HuggingFaceDescriptor(new Uri('https://gateway.example')), 'hf_'.self::BODY, self::CHAT_COMPLETION_ANSWER];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('movedEndpoints')]
    public function testADescriptorWithAnotherBaseUrlIsCheckedThere(SymfonyAiProvider $provider, string $key, array $answer): void
    {
        $providers = new SymfonyAiProviderCatalog([$provider]);
        /** @var ArrayObject<int, array{string, string, array<mixed>}> $calls */
        $calls = new ArrayObject();
        $check = new SymfonyAiKeyCheck($this->http($calls, static fn (): MockResponse => self::answer(200, $answer)), $providers, $this->tiers(), $this->log);

        $outcome = $check->check(ProviderKey::recognise($key, $providers));

        self::assertSame(KeyCheckOutcome::Accepted, $outcome);
        $urls = array_column($calls->getArrayCopy(), 1);
        self::assertCount(1, $urls);
        self::assertStringStartsWith('https://gateway.example/v1/', $urls[0]);
    }

    public function testTheHuggingFaceKeyIsCheckedAtTheRouterUrlOfTheSampleProjectDataFile(): void
    {
        $calls = $this->callsMadeFor('hf_'.self::BODY, self::CHAT_COMPLETION_ANSWER);

        self::assertSame([new SampleProjectData()->routerUrl()->toRawString().'/v1/chat/completions'], array_column($calls, 1));
    }

    public function testACheckModelInTheDescriptorOverridesTheLightTier(): void
    {
        $providers = new SymfonyAiProviderCatalog([new NamedCheckModel(new AnthropicDescriptor(), 'named-check-model')]);

        self::assertSame('named-check-model', $this->modelAsked($providers, $this->tiers()));
    }

    public function testTheFirstProjectWithItsLightTierOnTheProviderGivesTheModel(): void
    {
        $tiers = $this->tiers(['other' => Provider::OpenAi, 'first' => Provider::Anthropic, 'second' => Provider::Anthropic]);

        self::assertSame('light-first', $this->modelAsked(new SymfonyAiProviderCatalog(), $tiers));
    }

    public function testAProjectWhoseLightTierCannotBeReadIsSkipped(): void
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn(['broken', 'mapped']);
        $tiers->method('configured')->willReturnCallback(static fn (string $project): ResolvedModel => 'broken' === $project
            ? throw new TierNotMapped($project, Tier::Light) : new ResolvedModel(Provider::Anthropic, 'light-mapped'));

        self::assertSame('light-mapped', $this->modelAsked(new SymfonyAiProviderCatalog(), $tiers));
    }

    public function testWithoutALightTierOnTheProviderTheSampleProjectsLightModelIsAsked(): void
    {
        $tiers = $this->tiers(['other' => Provider::OpenAi]);

        self::assertSame(new SampleProjectData()->model(Provider::Anthropic, Tier::Light), $this->modelAsked(new SymfonyAiProviderCatalog(), $tiers));
    }

    /**
     * @return iterable<string, array{string, Provider, array<string, mixed>}>
     */
    public static function freshInstallAnswers(): iterable
    {
        yield 'anthropic' => ['sk-ant-'.self::BODY, Provider::Anthropic, self::ANTHROPIC_ANSWER];
        yield 'openai' => ['sk-'.self::BODY, Provider::OpenAi, self::OPENAI_ANSWER];
        yield 'hugging face' => ['hf_'.self::BODY, Provider::OpenAiCompatible, self::CHAT_COMPLETION_ANSWER];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('freshInstallAnswers')]
    public function testOnAFreshInstallWithNoProjectAKeyItsProviderAnswersIsAcceptedWithTheSampleProjectsLightModel(string $key, Provider $tierProvider, array $answer): void
    {
        /** @var ArrayObject<int, array{string, string, array<mixed>}> $calls */
        $calls = new ArrayObject();

        $outcome = $this->checkOnAFreshInstall($key, $calls, static fn (): MockResponse => self::answer(200, $answer));

        self::assertSame(KeyCheckOutcome::Accepted, $outcome);
        self::assertSame([new SampleProjectData()->model($tierProvider, Tier::Light)], array_map(static fn (array $call): mixed => $call[2]['model'] ?? null, array_values($calls->getArrayCopy())));
    }

    #[DataProvider('keys')]
    public function testOnAFreshInstallWithNoProjectAKeyItsProviderRefusesIsRefused(string $key): void
    {
        $outcome = $this->checkOnAFreshInstall($key, new ArrayObject(), static fn (): MockResponse => self::answer(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'Invalid key']]));

        self::assertSame(KeyCheckOutcome::Refused, $outcome);
    }

    public function testOnAFreshInstallWithNoProjectAnUnreachableProviderLeavesTheKeyNotChecked(): void
    {
        $outcome = $this->checkOnAFreshInstall('hf_'.self::BODY, new ArrayObject(), static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused']));

        self::assertSame(KeyCheckOutcome::NotChecked, $outcome);
        self::assertSame(['warning'], array_column($this->log->cleanLogs(), 0));
    }

    public function testAKeyOfAProviderTheCatalogDoesNotListIsNotCheckedAndNoCallIsMade(): void
    {
        $calls = new ArrayObject();
        $check = new SymfonyAiKeyCheck($this->http($calls, static fn (): MockResponse => self::answer(200, self::CHAT_COMPLETION_ANSWER)), new SymfonyAiProviderCatalog([new AnthropicDescriptor()]), $this->tiers(), $this->log);

        $outcome = $check->check(ProviderKey::recognise('hf_'.self::BODY, new SymfonyAiProviderCatalog()));

        self::assertSame([KeyCheckOutcome::NotChecked, 0], [$outcome, count($calls)]);
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

    public function testACheckThatFailsForAnotherReasonLogsAWarningNamingOnlyTheExceptionClass(): void
    {
        $key = 'sk-ant-'.self::BODY;

        $outcome = $this->check($key, new ArrayObject(), static fn (): never => throw new RuntimeException('Unknown model, request sent with '.$key));

        self::assertSame(KeyCheckOutcome::NotChecked, $outcome);
        self::assertSame([['warning', 'A provider key could not be checked.', ['provider' => 'anthropic', 'exception_class' => RuntimeException::class]]], $this->log->cleanLogs());
    }

    public function testTheWarningOfAFailedCheckCarriesNeitherTheExceptionMessageNorTheKey(): void
    {
        $key = 'sk-ant-'.self::BODY;

        $this->check($key, new ArrayObject(), static fn (): never => throw new RuntimeException('Unknown model, request sent with '.$key));

        $logged = json_encode($this->log->cleanLogs(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::BODY, $logged);
        self::assertStringNotContainsString('Unknown model', $logged);
    }

    public function testAProviderServerErrorIsLoggedAsAWarning(): void
    {
        $this->check('sk-ant-'.self::BODY, new ArrayObject(), static fn (): MockResponse => self::answer(500, ['error' => ['message' => 'overloaded']]));

        self::assertSame(['warning'], array_column($this->log->cleanLogs(), 0));
    }

    #[DataProvider('keys')]
    public function testARefusedKeyLogsNothing(string $key): void
    {
        $this->check($key, new ArrayObject(), static fn (): MockResponse => self::answer(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'Invalid key']]));

        self::assertSame([], $this->log->cleanLogs());
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('answers')]
    public function testAnAcceptedKeyLogsNothing(string $key, array $answer): void
    {
        $this->check($key, new ArrayObject(), static fn (): MockResponse => self::answer(200, $answer));

        self::assertSame([], $this->log->cleanLogs());
    }

    protected function setUp(): void
    {
        $this->log = new BufferingLogger();
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function answer(int $status, array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }

    /**
     * @param array<string, mixed> $answer
     *
     * @return list<array{string, string, array<mixed>}>
     */
    private function callsMadeFor(string $key, array $answer): array
    {
        /** @var ArrayObject<int, array{string, string, array<mixed>}> $calls */
        $calls = new ArrayObject();

        $this->check($key, $calls, static fn (): MockResponse => self::answer(200, $answer));

        return array_values($calls->getArrayCopy());
    }

    private function modelAsked(SymfonyAiProviderCatalog $providers, TierResolver $tiers): mixed
    {
        /** @var ArrayObject<int, array{string, string, array<mixed>}> $calls */
        $calls = new ArrayObject();
        $check = new SymfonyAiKeyCheck($this->http($calls, static fn (): MockResponse => self::answer(200, self::ANTHROPIC_ANSWER)), $providers, $tiers, $this->log);

        $check->check(ProviderKey::recognise('sk-ant-'.self::BODY, $providers));

        return array_first($calls->getArrayCopy())[2]['model'] ?? null;
    }

    /**
     * @param ArrayObject<int, array{string, string, array<mixed>}> $calls
     * @param Closure(array<mixed>): MockResponse                   $answer
     */
    private function check(string $key, ArrayObject $calls, Closure $answer): KeyCheckOutcome
    {
        return $this->keyCheck($this->tiers(), $calls, $answer)->check(ProviderKey::recognise($key, new SymfonyAiProviderCatalog()));
    }

    /**
     * @param ArrayObject<int, array{string, string, array<mixed>}> $calls
     * @param Closure(array<mixed>): MockResponse                   $answer
     */
    private function checkOnAFreshInstall(string $key, ArrayObject $calls, Closure $answer): KeyCheckOutcome
    {
        return $this->keyCheck($this->tiers([]), $calls, $answer)->check(ProviderKey::recognise($key, new SymfonyAiProviderCatalog()));
    }

    /**
     * @param ArrayObject<int, array{string, string, array<mixed>}> $calls
     * @param Closure(array<mixed>): MockResponse                   $answer
     */
    private function keyCheck(TierResolver $tiers, ArrayObject $calls, Closure $answer): SymfonyAiKeyCheck
    {
        return new SymfonyAiKeyCheck($this->http($calls, $answer), new SymfonyAiProviderCatalog(), $tiers, $this->log);
    }

    /**
     * @param ArrayObject<int, array{string, string, array<mixed>}> $calls
     * @param Closure(array<mixed>): MockResponse                   $answer
     */
    private function http(ArrayObject $calls, Closure $answer): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use ($calls, $answer): MockResponse {
            $body = $options['body'] ?? null;
            self::assertIsString($body);
            $request = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($request);
            $calls->append([$method, $url, $request]);

            return $answer($request);
        });
    }

    // The model is "light-<project>", so a test sees which project's light tier gave it; by default one project per provider, so every key has one.
    /**
     * @param array<string, Provider>|null $lightTiers
     */
    private function tiers(?array $lightTiers = null): TierResolver
    {
        $lightTiers ??= array_combine(array_map(static fn (Provider $provider): string => $provider->value, Provider::cases()), Provider::cases());
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn(array_map(strval(...), array_keys($lightTiers)));
        $tiers->method('configured')->willReturnCallback(static fn (string $project): ResolvedModel => new ResolvedModel($lightTiers[$project], 'light-'.$project));

        return $tiers;
    }
}
