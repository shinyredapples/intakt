<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\Infrastructure\Repository\ReviewCheckout;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\TraceablePlatform;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Uid\UuidV7;

// Runs the reviewer over the Anthropic bridge with a scripted HTTP client, so the request the bridge builds and the usage it reads back are the real ones.
#[CoversClass(ReviewerAgent::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(FencedToolbox::class)]
#[UsesClass(MalformedToolCalls::class)]
#[UsesClass(Fence::class)]
#[UsesClass(ReviewRequest::class)]
#[UsesClass(ReviewerConversation::class)]
#[UsesClass(ReviewBrief::class)]
#[UsesClass(ChangeUnderReview::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(ReadFile::class)]
#[UsesClass(StrictVerdictConverter::class)]
#[UsesClass(StrictVerdictSubscriber::class)]
final class ReviewerPromptCachingTest extends KernelTestCase
{
    private ReviewCheckout $fixture;

    /**
     * @var list<string>
     */
    private array $requests = [];

    public function testRoundTwoReadsTheTokensRoundOneCached(): void
    {
        $platform = $this->reviewWithTwoRounds();

        $usages = $this->usagesOf($platform);

        self::assertSame(1_200, $usages[0]->getCacheCreationTokens());
        self::assertNull($usages[0]->getCacheReadTokens());
        self::assertSame(1_200, $usages[1]->getCacheReadTokens());
    }

    public function testEveryRoundMarksSystemToolsAndTheLatestMessageAsCacheable(): void
    {
        $this->reviewWithTwoRounds();

        self::assertCount(2, $this->requests);
        foreach ($this->requests as $body) {
            /** @var array{system: non-empty-list<array{cache_control?: array<string, string>}>, tools: non-empty-list<array{cache_control?: array<string, string>}>, messages: non-empty-list<array{content: non-empty-list<array{cache_control?: array<string, string>}>}>} $request */
            $request = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['type' => 'ephemeral'], array_last($request['system'])['cache_control'] ?? null);
            self::assertSame(['type' => 'ephemeral'], array_last($request['tools'])['cache_control'] ?? null);
            self::assertSame(['type' => 'ephemeral'], array_last(array_last($request['messages'])['content'])['cache_control'] ?? null);
        }
    }

    protected function setUp(): void
    {
        $this->fixture = new ReviewCheckout();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    private function reviewWithTwoRounds(): TraceablePlatform
    {
        $responses = [
            $this->anthropic([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'read_file', 'input' => ['path' => 'src/Cart.php']]], ['cache_creation_input_tokens' => 1_200, 'input_tokens' => 40]),
            $this->anthropic([['type' => 'text', 'text' => VerdictDocument::fromTheDesign()->with('resolved', [])->json()]], ['cache_read_input_tokens' => 1_200, 'input_tokens' => 80]),
        ];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): JsonMockResponse {
            self::assertIsString($options['body']);

            // The reviewer asks to stream first, and this provider refuses, so it asks again unstreamed.
            if (str_contains($options['body'], '"stream":true')) {
                return new JsonMockResponse(['error' => ['message' => 'stream refused']], ['http_code' => 400]);
            }

            $this->requests[] = $options['body'];

            return array_shift($responses) ?? self::fail('The reviewer asked for a third round.');
        });
        $platform = new TraceablePlatform(Factory::createPlatform('placeholder', $http, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher));
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));

        $reviewer->review(new ReviewRequest($this->fixture->checkout, new ReviewBrief(new ChangeUnderReview('alpha', 'Add a cart.', 'diff --git a/a b/a'), [], 1), new ReviewerConversation('You review for correctness.', new UuidV7())), $platform, 'claude-sonnet-5-5');

        return $platform;
    }

    /**
     * @param list<array<string, mixed>> $content
     * @param array<string, int>         $usage
     */
    private function anthropic(array $content, array $usage): JsonMockResponse
    {
        return new JsonMockResponse([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => $content,
            'stop_reason' => 'tool_use' === $content[0]['type'] ? 'tool_use' : 'end_turn',
            'usage' => $usage + ['output_tokens' => 10],
        ]);
    }

    /**
     * @return list<TokenUsageInterface>
     */
    private function usagesOf(TraceablePlatform $platform): array
    {
        $answered = array_filter($platform->getCalls(), static fn (array $call): bool => true !== ($call['options']['stream'] ?? false));

        return array_values(array_map(static function (array $call): TokenUsageInterface {
            $result = $call['result'];
            self::assertInstanceOf(DeferredResult::class, $result);
            $usage = $result->getResult()->getMetadata()->get('token_usage');
            self::assertInstanceOf(TokenUsageInterface::class, $usage);

            return $usage;
        }, $answered));
    }
}
