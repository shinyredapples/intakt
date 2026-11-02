<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictStreamListener;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\Infrastructure\Repository\ReviewCheckout;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\TraceablePlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\UuidV7;

// Runs the reviewer over the Anthropic bridge with a scripted HTTP client, so the request the bridge builds and the usage it reads back from the event stream are the real ones.
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
#[UsesClass(Finding::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(ReadFile::class)]
#[UsesClass(StrictVerdictConverter::class)]
#[UsesClass(StrictVerdictStreamListener::class)]
#[UsesClass(StrictVerdictSubscriber::class)]
final class ReviewerPromptCachingTest extends KernelTestCase
{
    private ReviewCheckout $fixture;

    private Verdict $verdict;

    /**
     * @var list<string>
     */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->fixture = new ReviewCheckout();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testRoundTwoReadsTheTokensRoundOneCached(): void
    {
        $platform = $this->reviewWithTwoRounds();

        $usages = $this->usagesOf($platform);

        self::assertSame(1_200, $usages[0]->getCacheCreationTokens());
        self::assertNull($usages[0]->getCacheReadTokens());
        self::assertSame(1_200, $usages[1]->getCacheReadTokens());
    }

    public function testTheStreamedVerdictSurvivesTheTracedPlatformOfADebugInstall(): void
    {
        $this->reviewWithTwoRounds();

        self::assertSame(Decision::Changes, $this->verdict->verdict);
        self::assertSame(['F1', 'F2'], $this->verdict->resolved);
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

    private function reviewWithTwoRounds(): TraceablePlatform
    {
        $responses = [
            AnthropicStream::answering([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'read_file', 'input' => ['path' => 'src/Cart.php']]], ['cache_creation_input_tokens' => 1_200, 'input_tokens' => 40, 'output_tokens' => 10]),
            AnthropicStream::answering([['type' => 'text', 'text' => VerdictDocument::fromTheDesign()->json()]], ['cache_read_input_tokens' => 1_200, 'input_tokens' => 80, 'output_tokens' => 10]),
        ];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            self::assertIsString($options['body']);
            $this->requests[] = $options['body'];

            return array_shift($responses) ?? self::fail('The reviewer asked for a third round.');
        });
        // Traced as the bundle traces every platform of a debug install, so the streamed verdict is read through the traced stream here.
        $platform = new TraceablePlatform(Factory::createPlatform('placeholder', $http, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher));
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new RecordedRoundUpdates()));
        $open = [$this->openFinding('F1'), $this->openFinding('F2')];

        $this->verdict = $reviewer->review(new ReviewRequest($this->fixture->checkout, new ReviewBrief(new ChangeUnderReview('alpha', 'Add a cart.', 'diff --git a/a b/a'), $open), new ReviewerConversation('You review for correctness.', new UuidV7())), $platform, 'claude-sonnet-5-5');

        return $platform;
    }

    private function openFinding(string $id): Finding
    {
        return new Finding($id, Severity::P2, null, null, null, 'Earlier.', FixableBy::Builder, false, null, null);
    }

    /**
     * @return list<TokenUsageInterface>
     */
    private function usagesOf(TraceablePlatform $platform): array
    {
        return array_values(array_map(static function (array $call): TokenUsageInterface {
            $result = $call['result'];
            self::assertInstanceOf(DeferredResult::class, $result);
            $usage = $result->getResult()->getMetadata()->get('token_usage');
            self::assertInstanceOf(TokenUsageInterface::class, $usage);

            return $usage;
        }, $platform->getCalls()));
    }
}
