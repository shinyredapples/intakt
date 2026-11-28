<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ai\Infrastructure\TypedRejectionConverter;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JobReviewer;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\JudgeAgent;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\ProviderUnreachable;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Ai\Infrastructure\NoPinnedProviderCooldowns;
use Intakt\Tests\Core\Ai\Infrastructure\OwnersOrderRanking;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\Infrastructure\RemembersNothing;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\UuidV7;
use Throwable;

// The real path of a failed name lookup: the Anthropic bridge built by its own factory over an HTTP client whose response fails when it is read, as Symfony's client reports a lookup, so no second account is tried and the vendor asks the reviewer again.
#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(AccountFailoverPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[CoversClass(ProviderUnreachable::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(TypedRejectionConverter::class)]
#[UsesClass(TierRoutedPlatform::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(Account::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(FailureInWords::class)]
final class AnthropicUnreachableRetryTest extends KernelTestCase
{
    private const string NOW = '2026-11-16 12:00:00.000 UTC';

    /**
     * @var list<bool>
     */
    private array $streamed = [];

    public function testAReviewerWhoseNameLookupFailsOnceIsAskedOnceMoreAndItsSecondVerdictCounts(): void
    {
        $verdict = $this->vendor($this->unreachable(...), fn (): MockResponse => $this->namedFinding(withEnd: true))->review('alpha', Tier::Strong, $this->request())->verdict;

        self::assertSame([Decision::Changes, 1], [$verdict->verdict, count($verdict->findings)]);
        self::assertSame([true, true], $this->streamed);
    }

    public function testAReviewerWhoseNameLookupFailsTwiceFailsWithTheCauseThatTheModelEndpointCouldNotBeReached(): void
    {
        $vendor = $this->vendor($this->unreachable(...), $this->unreachable(...));

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(ProviderUnreachable::class, $failure);
        self::assertSame('the model endpoint could not be reached', new FailureInWords()->of($failure::class));
        self::assertSame([true, true], $this->streamed);
    }

    // Unstreamed, because a reviewer on a provider that streams is asked without a stream only after a cut stream, and that retry is final like every retry.
    public function testAnUnstreamedRetryAfterACutStreamWhoseNameLookupFailsFailsTheReviewerWithTheCauseThatTheModelEndpointCouldNotBeReached(): void
    {
        $vendor = $this->vendor(fn (): MockResponse => $this->namedFinding(withEnd: false), $this->unreachable(...));

        $failure = $this->thrownBy(fn (): VendorReview => $vendor->review('alpha', Tier::Strong, $this->request()));

        self::assertInstanceOf(ProviderUnreachable::class, $failure);
        self::assertSame('the model endpoint could not be reached', new FailureInWords()->of($failure::class));
        self::assertSame([true, false], $this->streamed);
    }

    // Failed when read, because Symfony's client reports a failed lookup there and not when the request is sent.
    private function unreachable(): MockResponse
    {
        return new MockResponse('', ['error' => 'Could not resolve host: api.anthropic.com']);
    }

    // Optionally cut before its end, because the bridge reports a stream without message_stop as incomplete and the vendor then asks without a stream.
    private function namedFinding(bool $withEnd): MockResponse
    {
        $usage = ['input_tokens' => 150, 'output_tokens' => 40];
        $events = [
            'message_start' => ['type' => 'message_start', 'message' => ['id' => 'msg_01', 'type' => 'message', 'role' => 'assistant', 'model' => 'big', 'content' => [], 'stop_reason' => null, 'usage' => $usage]],
            'content_block_start' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            'content_block_delta' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => VerdictDocument::fromTheDesign()->with('resolved', [])->json()]],
            'content_block_stop' => ['type' => 'content_block_stop', 'index' => 0],
            'message_delta' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => $usage],
            'message_stop' => ['type' => 'message_stop'],
        ];
        if (!$withEnd) {
            $events = array_slice($events, 0, 3);
        }
        $body = implode('', array_map(static fn (string $event, array $data): string => sprintf("event: %s\ndata: %s\n\n", $event, json_encode($data, JSON_THROW_ON_ERROR)), array_keys($events), $events));

        return new MockResponse($body, ['response_headers' => ['content-type: text/event-stream']]);
    }

    private function thrownBy(Closure $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    /**
     * @param Closure(): MockResponse $first
     * @param Closure(): MockResponse $second
     */
    private function vendor(Closure $first, Closure $second): SymfonyAiVendor
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($first, $second): MockResponse {
            $this->streamed[] = is_string($options['body']) && str_contains($options['body'], '"stream":true');

            return 1 === count($this->streamed) ? $first() : $second();
        });
        $capabilities = [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::OUTPUT_STRUCTURED, Capability::OUTPUT_STREAMING, Capability::TOOL_CALLING];
        $bridge = Factory::createPlatform('test', $http, new ModelCatalog(['big' => ['class' => Claude::class, 'capabilities' => $capabilities]]), eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $accounts = new AccountFailoverPlatform(Provider::Anthropic, [Account::DEFAULT => $bridge], new NoCooldowns(), new MockClock(self::NOW), new NullLogger(), new NoPinnedProviderCooldowns(), new OwnersOrderRanking());
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]]], []);
        $tiers = new TierPlatform($resolver, new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $accounts]), new NoCooldowns(), new NullLogger());
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiVendor($tiers, $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger(new InMemoryLedger(), new MockClock(self::NOW), new NullLogger(), StoredSerializerFactory::create()), $limit, new MockClock(self::NOW), self::createStub(ReviewServerAccesses::class), self::createStub(JobReviewer::class));
    }

    private function request(): ReviewRequest
    {
        $open = new Finding('F1', Severity::P2, null, null, null, 'Earlier.', FixableBy::Builder, false, null, null);

        return new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief(new ChangeUnderReview('alpha', 'Review the change.', 'diff --git a/a b/a'), [$open], 1), new ReviewerConversation('You review for correctness.', new UuidV7()));
    }
}
