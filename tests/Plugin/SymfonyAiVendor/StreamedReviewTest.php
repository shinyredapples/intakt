<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\JudgeAgent;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictStreamListener;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Infrastructure\AnthropicStream;
use Intakt\Tests\Core\Review\Infrastructure\RemembersNothing;
use Intakt\Tests\Core\Review\Infrastructure\Repository\ReviewCheckout;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\UuidV7;

// Runs a review over the Anthropic bridge with scripted event streams, so the streamed request, the published text and the usage settled from the stream are the real ones.
#[CoversClass(UsageLedger::class)]
#[CoversClass(SymfonyAiVendor::class)]
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
#[UsesClass(VendorReview::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(ReadFile::class)]
#[UsesClass(StrictVerdictConverter::class)]
#[UsesClass(StrictVerdictStreamListener::class)]
#[UsesClass(StrictVerdictSubscriber::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(TierRequest::class)]
#[UsesClass(TierRoutedPlatform::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(UsageReservation::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
final class StreamedReviewTest extends KernelTestCase
{
    private const string NOW = '2026-11-02 12:00:00.000 UTC';

    private const string APPROVE = '{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}';

    private ReviewCheckout $fixture;

    private InMemoryLedger $ledger;

    private RecordedRoundUpdates $updates;

    private UuidV7 $reviewer;

    /**
     * @var list<string>
     */
    private array $requests = [];

    #[Override]
    protected function setUp(): void
    {
        $this->fixture = new ReviewCheckout();
        $this->ledger = new InMemoryLedger();
        $this->updates = new RecordedRoundUpdates();
        $this->reviewer = new UuidV7();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testEveryRoundOfTheReviewAsksAnthropicToStream(): void
    {
        $this->reviewWithAToolRoundAndAStreamedVerdict();

        self::assertCount(2, $this->requests);
        foreach ($this->requests as $body) {
            self::assertStringContainsString('"stream":true', $body);
        }
    }

    public function testTheStreamedVerdictIsTheOneAnthropicAnsweredAndItsTextWasPublishedAsItArrived(): void
    {
        $verdict = $this->reviewWithAToolRoundAndAStreamedVerdict();

        self::assertSame(Decision::Approve, $verdict->verdict);
        self::assertSame([
            sprintf('tool %s read_file', $this->reviewer->toRfc4122()),
            sprintf('text %s {"verdict":"approve",', $this->reviewer->toRfc4122()),
            sprintf('text %s "findings":[],"resolved":[],"follow_ups":[]}', $this->reviewer->toRfc4122()),
        ], $this->updates->seen);
    }

    public function testAStreamedReviewSettlesWithTheTokensOfEveryRoundReadFromTheStreams(): void
    {
        $this->reviewWithAToolRoundAndAStreamedVerdict();

        [$started, $settled] = $this->ledger->payloads();
        self::assertSame(['started', 'settled'], [$started['phase'], $settled['phase']]);
        /** @var list<array<string, int|string|null>> $usages */
        $usages = $settled['usages'];
        self::assertSame([
            ['claude-sonnet-5-5', 40, null, 1_200],
            ['claude-sonnet-5-5', null, 10, null],
            ['claude-sonnet-5-5', 80, null, null],
            ['claude-sonnet-5-5', null, 25, null],
        ], array_map(static fn (array $usage): array => [$usage['model'], $usage['prompt'], $usage['completion'], $usage['cache_creation']], $usages));
    }

    private function reviewWithAToolRoundAndAStreamedVerdict(): Verdict
    {
        $responses = [
            AnthropicStream::answering([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'read_file', 'input' => ['path' => 'src/Cart.php']]], ['input_tokens' => 40, 'cache_creation_input_tokens' => 1_200, 'output_tokens' => 10]),
            AnthropicStream::answering([['type' => 'text', 'text' => '{"verdict":"approve",'], ['type' => 'text', 'text' => '"findings":[],"resolved":[],"follow_ups":[]}']], ['input_tokens' => 80, 'output_tokens' => 25]),
        ];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            self::assertIsString($options['body']);
            $this->requests[] = $options['body'];

            return array_shift($responses) ?? self::fail('The reviewer asked for a third round.');
        });
        $platform = Factory::createPlatform('placeholder', $http, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $request = new ReviewRequest($this->fixture->checkout, new ReviewBrief(new ChangeUnderReview('alpha', 'Add a cart.', 'diff --git a/a b/a'), []), new ReviewerConversation('You review for correctness.', $this->reviewer, new UuidV7()));

        return $this->vendor($platform)->review('alpha', Tier::Strong, $request)->verdict;
    }

    private function vendor(PlatformInterface $platform): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']]],
        ], []);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), $this->updates));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->ledger, new MockClock(self::NOW), new NullLogger()), $limit);
    }
}
