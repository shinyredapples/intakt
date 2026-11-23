<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Review\Infrastructure\FinalStreamedUsage;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictStreamListener;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Anthropic\ResultConverter;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(UsageLedger::class)]
#[CoversClass(FinalStreamedUsage::class)]
#[UsesClass(StrictVerdictConverter::class)]
#[UsesClass(StrictVerdictStreamListener::class)]
#[UsesClass(UsageReservation::class)]
#[UsesClass(UsageSettled::class)]
#[UsesClass(TokenUsageRow::class)]
final class AnthropicUsageTest extends TestCase
{
    private const array FIGURES = ['prompt', 'cache_read', 'cache_creation', 'completion'];

    public function testAStreamedCallThatUsedAToolRecordsTheInputAndCacheTokensOfEachRound(): void
    {
        $ledger = new InMemoryLedger();
        $rounds = [$this->streamedRound('tool-use.sse'), $this->streamedRound('final-text.sse')];

        $this->settle($ledger, ...$rounds);

        self::assertSame([
            ['prompt' => 1200, 'completion' => 40, 'cache_creation' => 3000, 'cache_read' => 0],
            ['prompt' => 150, 'completion' => 908, 'cache_creation' => 0, 'cache_read' => 3000],
        ], $this->figuresOf($ledger));
    }

    public function testAnUnstreamedCallThatUsedAToolRecordsTheInputAndCacheTokensOfEachRound(): void
    {
        $ledger = new InMemoryLedger();
        $toolUse = ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'read_file', 'input' => ['path' => 'src/Kernel.php']];
        $text = ['type' => 'text', 'text' => VerdictDocument::fromTheDesign()->json()];
        $rounds = [
            $this->unstreamedRound($toolUse, ['input_tokens' => 1200, 'cache_creation_input_tokens' => 3000, 'cache_read_input_tokens' => 0, 'output_tokens' => 40]),
            $this->unstreamedRound($text, ['input_tokens' => 150, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 3000, 'output_tokens' => 908]),
        ];

        $this->settle($ledger, ...$rounds);

        self::assertSame([
            ['prompt' => 1200, 'completion' => 40, 'cache_creation' => 3000, 'cache_read' => 0],
            ['prompt' => 150, 'completion' => 908, 'cache_creation' => 0, 'cache_read' => 3000],
        ], $this->figuresOf($ledger));
    }

    public function testAStreamedCallWhoseProviderReportsNoInputTokensRecordsThemAsUnknown(): void
    {
        $ledger = new InMemoryLedger();
        $withoutInput = (string) preg_replace('/"input_tokens":\d+,"cache_creation_input_tokens":\d+,"cache_read_input_tokens":\d+,/', '', $this->recorded('final-text.sse'));

        $this->settle($ledger, $this->streamedRoundOf($withoutInput));

        self::assertSame([['prompt' => null, 'completion' => 908, 'cache_creation' => null, 'cache_read' => null]], $this->figuresOf($ledger));
    }

    // Consumed like the agent consumes a round, so the stream's listeners have run when the call settles.
    private function streamedRound(string $fixture): DeferredResult
    {
        return $this->streamedRoundOf($this->recorded($fixture));
    }

    private function settle(InMemoryLedger $ledger, DeferredResult ...$rounds): void
    {
        new UsageLedger($ledger, new MockClock('2026-11-09T00:00:00+00:00'), new NullLogger(), StoredSerializerFactory::create())->settle(new UsageReservation('a call'), ...$rounds);
    }

    /**
     * @return list<array<string, int|null>>
     */
    private function figuresOf(InMemoryLedger $ledger): array
    {
        /** @var list<array<string, int|null>> $usages */
        $usages = $ledger->payloads()[0]['usages'];

        return array_map(static fn (array $usage): array => array_intersect_key($usage, array_flip(self::FIGURES)), $usages);
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, int>   $usage
     */
    private function unstreamedRound(array $content, array $usage): DeferredResult
    {
        $answer = ['id' => 'msg_01', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'content' => [$content], 'stop_reason' => 'end_turn', 'usage' => $usage];
        $response = new MockHttpClient(new MockResponse(json_encode($answer, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]))->request('POST', 'https://api.anthropic.com/v1/messages');
        $round = $this->roundOf(new RawHttpResult($response), []);
        $round->getResult();

        return $round;
    }

    private function recorded(string $fixture): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/anthropic/'.$fixture);
    }

    private function streamedRoundOf(string $events): DeferredResult
    {
        // Wrapped like the platform's own client, because Symfony AI reads a stream's events through an event source.
        $response = new EventSourceHttpClient(new MockHttpClient(new MockResponse($events, ['response_headers' => ['content-type: text/event-stream']])))->request('POST', 'https://api.anthropic.com/v1/messages');
        $round = $this->roundOf(new RawHttpResult($response), ['stream' => true]);
        $stream = $round->getResult();
        self::assertInstanceOf(StreamResult::class, $stream);
        iterator_to_array($stream->getContent(), preserve_keys: false);

        return $round;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function roundOf(RawHttpResult $raw, array $options): DeferredResult
    {
        return new DeferredResult(new StrictVerdictConverter(new ResultConverter(), StoredSerializerFactory::create(), self::createStub(ValidatorInterface::class)), $raw, $options);
    }
}
