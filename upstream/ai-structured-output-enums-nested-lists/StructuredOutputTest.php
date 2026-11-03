<?php

declare(strict_types=1);

namespace Intakt\Upstream\StructuredOutput;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Exception\ExceptionInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

enum Priority: string
{
    case High = 'high';
    case Low = 'low';
}

final class Step
{
    public string $title;

    public Priority $priority;
}

final class Plan
{
    public string $name;

    public Priority $priority;

    /** @var list<Step> */
    public array $steps = [];
}

// The provider answers come from the real bridges' wire formats through a mock HTTP client: no network, no credentials.
#[CoversNothing]
final class StructuredOutputTest extends TestCase
{
    private const string PLAN = '{"name":"ship","priority":"high","steps":[{"title":"build","priority":"low"},{"title":"test","priority":"high"}]}';

    #[DataProvider('providers')]
    public function testAnEnumAndANestedListFillTheTypedObject(string $provider, string $model): void
    {
        $platform = $this->platform($provider, new MockResponse($this->whole($provider, self::PLAN), ['response_headers' => ['content-type: application/json']]));

        $plan = $platform->invoke($model, $this->messages(), ['response_format' => Plan::class])->asObject();

        self::assertInstanceOf(Plan::class, $plan);
        self::assertSame(Priority::High, $plan->priority);
        self::assertCount(2, $plan->steps);
        self::assertContainsOnlyInstancesOf(Step::class, $plan->steps);
        self::assertSame(Priority::Low, $plan->steps[0]->priority);
        self::assertSame('test', $plan->steps[1]->title);
    }

    #[DataProvider('providers')]
    public function testAStreamTruncatedMidArrayThrowsATypedExceptionAndReturnsNoObject(string $provider, string $model): void
    {
        $cut = '{"name":"ship","priority":"high","steps":[{"title":"build","priority":"low"},{"title":"te';
        $platform = $this->platform($provider, new MockResponse($this->truncatedStream($provider, $cut), ['response_headers' => ['content-type: text/event-stream']]));
        $result = $platform->invoke($model, $this->messages(), ['response_format' => Plan::class, 'stream' => true]);
        $object = null;
        $thrown = null;

        try {
            $object = $result->asObject();
        } catch (ExceptionInterface $exception) {
            $thrown = $exception;
        }

        self::assertNull($object, 'a truncated stream must not give back an object');
        self::assertInstanceOf(ExceptionInterface::class, $thrown, 'a truncated stream must end in a typed platform exception');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providers(): iterable
    {
        yield 'anthropic' => ['anthropic', 'claude-haiku-4-5'];
        yield 'openai' => ['openai', 'gpt-4o'];
        yield 'generic' => ['generic', 'local-model'];
    }

    private function platform(string $provider, MockResponse $response): PlatformInterface
    {
        $events = new EventDispatcher();
        $events->addSubscriber(new PlatformSubscriber());
        $http = new MockHttpClient($response, 'https://localhost');

        return match ($provider) {
            'anthropic' => AnthropicFactory::createPlatform('key', $http, eventDispatcher: $events),
            'openai' => OpenAiFactory::createPlatform('sk-test', $http, eventDispatcher: $events),
            'generic' => GenericFactory::createPlatform('https://localhost', 'key', $http, eventDispatcher: $events),
        };
    }

    private function messages(): MessageBag
    {
        return new MessageBag(Message::ofUser('Plan the release.'));
    }

    private function whole(string $provider, string $text): string
    {
        return json_encode(match ($provider) {
            'anthropic' => ['id' => 'm1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-4-5', 'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]],
            'openai' => ['id' => 'r1', 'status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]]],
            default => ['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']]],
        }, JSON_THROW_ON_ERROR);
    }

    // The stream stops after the last text delta, with no closing event, as a dropped connection leaves it.
    private function truncatedStream(string $provider, string $text): string
    {
        $events = match ($provider) {
            'anthropic' => [
                ['message_start', ['type' => 'message_start', 'message' => ['id' => 'm1', 'model' => 'claude-haiku-4-5', 'usage' => ['input_tokens' => 1]]]],
                ['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
                ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]]],
            ],
            'openai' => [
                ['response.output_text.delta', ['type' => 'response.output_text.delta', 'delta' => $text]],
            ],
            default => [
                ['message', ['choices' => [['index' => 0, 'delta' => ['content' => $text], 'finish_reason' => null]]]],
            ],
        };

        return implode('', array_map(
            static fn (array $event): string => sprintf("event: %s\ndata: %s\n\n", $event[0], json_encode($event[1], JSON_THROW_ON_ERROR)),
            $events,
        ));
    }
}
