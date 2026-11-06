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
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\Test\Replay\CassetteHttpClient;
use Symfony\AI\Platform\Test\Replay\HttpCassette;
use Symfony\Component\EventDispatcher\EventDispatcher;

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

// Each answer is replayed from a redacted cassette in cassettes/, which also checks that the request matches the recorded one; no network, no credentials.
#[CoversNothing]
final class StructuredOutputTest extends TestCase
{
    #[DataProvider('providers')]
    public function testAnEnumAndANestedListFillTheTypedObject(string $provider, string $model): void
    {
        $platform = $this->platform($provider, 'whole');

        $plan = $platform->invoke($model, $this->messages(), ['response_format' => Plan::class])->asObject();

        $this->assertWholePlan($plan);
    }

    // The control for the cut below: the same closed stream carrying the whole plan fills the object, so the exception there comes from the cut.
    #[DataProvider('providers')]
    public function testAClosedStreamOfTheWholePlanFillsTheTypedObject(string $provider, string $model): void
    {
        $platform = $this->platform($provider, 'stream-whole');

        $plan = $platform->invoke($model, $this->messages(), ['response_format' => Plan::class, 'stream' => true])->asObject();

        $this->assertWholePlan($plan);
    }

    /**
     * @param class-string<ExceptionInterface> $expected
     */
    #[DataProvider('truncations')]
    public function testAStreamStoppedByTheTokenLimitMidArrayThrowsATypedExceptionAndReturnsNoObject(string $provider, string $model, string $expected, ?string $message = null): void
    {
        $result = $this->platform($provider, 'stream-cut')->invoke($model, $this->messages(), ['response_format' => Plan::class, 'stream' => true]);
        $object = null;
        $thrown = null;

        try {
            $object = $result->asObject();
        } catch (ExceptionInterface $exception) {
            $thrown = $exception;
        }

        self::assertNull($object, 'a truncated stream must not give back an object');
        self::assertInstanceOf($expected, $thrown, 'a truncated stream must end in a typed platform exception');
        self::assertNotInstanceOf(IncompleteStreamException::class, $thrown, 'the stream is closed, so the exception must come from the cut, not from a missing end');

        // A cassette request mismatch throws the same RuntimeException, so the message tells the decoder's refusal from a stale cassette.
        if (null !== $message) {
            self::assertSame($message, $thrown->getMessage());
        }
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

    // The generic bridge raises MaxOutputTokensException for a cut tool call only; a cut text reaches the structured-output decoder, which refuses the broken JSON.
    /**
     * @return iterable<string, array{0: string, 1: string, 2: class-string<ExceptionInterface>, 3?: string}>
     */
    public static function truncations(): iterable
    {
        yield 'anthropic' => ['anthropic', 'claude-haiku-4-5', MaxOutputTokensException::class];
        yield 'openai' => ['openai', 'gpt-4o', MaxOutputTokensException::class];
        yield 'generic' => ['generic', 'local-model', RuntimeException::class, 'Cannot json decode the streamed content.'];
    }

    private function platform(string $provider, string $case): PlatformInterface
    {
        $events = new EventDispatcher();
        $events->addSubscriber(new PlatformSubscriber());
        $http = new CassetteHttpClient(new HttpCassette(__DIR__.'/cassettes/'.$provider.'-'.$case.'.json'), record: false);

        return match ($provider) {
            'anthropic' => AnthropicFactory::createPlatform('probe-key', $http, eventDispatcher: $events),
            'openai' => OpenAiFactory::createPlatform('sk-probe', $http, eventDispatcher: $events),
            'generic' => GenericFactory::createPlatform('https://localhost', 'probe-key', $http, eventDispatcher: $events),
        };
    }

    private function messages(): MessageBag
    {
        return new MessageBag(Message::ofUser('Plan the release.'));
    }

    private function assertWholePlan(mixed $plan): void
    {
        self::assertInstanceOf(Plan::class, $plan);
        self::assertSame(Priority::High, $plan->priority);
        self::assertCount(2, $plan->steps);
        self::assertContainsOnlyInstancesOf(Step::class, $plan->steps);
        self::assertSame(Priority::Low, $plan->steps[0]->priority);
        self::assertSame('test', $plan->steps[1]->title);
    }
}
