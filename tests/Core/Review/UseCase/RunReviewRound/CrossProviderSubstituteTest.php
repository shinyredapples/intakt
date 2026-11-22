<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ai\Infrastructure\TypedRejectionConverter;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\JudgeAgent;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMoves;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMovesToAnotherAccount;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerRateLimited;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Core\Ai\Infrastructure\FailingWhenRead;
use Intakt\Tests\Core\Ai\Infrastructure\NoPinnedProviderCooldowns;
use Intakt\Tests\Core\Ai\Infrastructure\RecordingCalls;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

// Through the real vendor stack down to in-memory providers, so the substitution is proven end to end: the tier's own provider fails with a revoked key or a limit and the next provider is its configured substitute.
#[Group('persistence')]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(AccountLimitedVendor::class)]
#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(TierPlatform::class)]
#[CoversClass(RoundReviewer::class)]
#[CoversClass(RoundLedger::class)]
#[UsesClass(ReviewerLeases::class)]
#[UsesClass(ReviewerMoves::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(NoProviderLeft::class)]
#[UsesClass(ProviderRejectedTheKey::class)]
#[UsesClass(VendorAccountOut::class)]
#[UsesClass(VendorReview::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(TypedRejectionConverter::class)]
#[UsesClass(ReviewerRateLimited::class)]
#[UsesClass(EveryProviderOut::class)]
#[UsesClass(AccountFailoverPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(AccountCooldownWindows::class)]
#[UsesClass(Account::class)]
final class CrossProviderSubstituteTest extends KernelTestCase
{
    private RoundFixture $fixture;
    private AccountCooldownWindows $cooldowns;
    private VendorAccountLimit $limit;

    /**
     * @var list<string>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);
        $this->cooldowns = new AccountCooldownWindows(new ReadingsInMemory(new NativeClock()), new ArrayAdapter(), new LockFactory(new InMemoryStore()), new NativeClock(), $this->fixture->ledger(), new NullLogger(), StoredSerializerFactory::create(), [Provider::Anthropic->value => [Account::DEFAULT], Provider::OpenAi->value => [Account::DEFAULT], Provider::ClaudeCode->value => [Account::DEFAULT]]);
    }

    public function testARoundWithOneProvidersKeyRevokedIsReviewedByTheSubstituteAndOwesACatchUp(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $vendor = $this->vendor(new FailingWhenRead(new AuthenticationException('revoked')), $this->answering());

        $this->deliverUntilItStays($message, $vendor);

        $reviewer = $this->fixture->round($round->id()->toRfc4122())->reviewers()[0];
        self::assertSame(RoundState::Complete, $this->fixture->round($round->id()->toRfc4122())->state());
        self::assertSame(ReviewerState::Verdict, $reviewer->state());
        self::assertSame(Provider::OpenAi, $reviewer->substitute());
        self::assertTrue($reviewer->owesCatchUp());
        self::assertSame(['openai'], $this->payloadValues(LedgerEventKind::CatchUpOwed, 'substitute'));
        self::assertSame(['role-1'], $this->payloadValues(LedgerEventKind::CatchUpOwed, 'role'));
        self::assertSame(1, count(array_filter($this->fixture->ledgerKinds(), static fn (LedgerEventKind $kind): bool => LedgerEventKind::VerdictReceived === $kind)));
    }

    public function testAReviewerWhoseOwnProviderAnswersOwesNoCatchUp(): void
    {
        $round = $this->fixture->start(1);
        $vendor = $this->vendor($this->answering(), new FailingWhenRead(new AuthenticationException('never called')));

        $this->fixture->deliver($this->fixture->bus->messages[0], $vendor);

        $reviewer = $this->fixture->round($round->id()->toRfc4122())->reviewers()[0];
        self::assertFalse($reviewer->owesCatchUp());
        self::assertSame([], $this->payloadValues(LedgerEventKind::CatchUpOwed, 'substitute'));
    }

    public function testASubstituteThatCannotReadTheCheckoutCannotApprove(): void
    {
        $round = $this->fixture->start(1);
        $this->cooldowns->coolDown(new Account(Provider::Anthropic, Account::DEFAULT), 300);
        $vendor = $this->vendor($this->answering(), $this->answering('approve'), Provider::ClaudeCode);

        $this->fixture->deliver($this->fixture->bus->messages[0], $vendor);

        $reviewer = $this->fixture->round($round->id()->toRfc4122())->reviewers()[0];
        self::assertSame(Provider::ClaudeCode, $reviewer->substitute());
        self::assertStringContainsString('"verdict":"blocked"', (string) $reviewer->verdictJson());
    }

    public function testAnOutageOfEveryProviderEndsTheReviewerBlockedWithTheReason(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $vendor = $this->vendor(new FailingWhenRead(new AuthenticationException('revoked')), new FailingWhenRead(new AuthenticationException('revoked too')));

        $this->deliverUntilItStays($message, $vendor, 4);

        $stored = $this->fixture->round($round->id()->toRfc4122());
        $reviewer = $stored->reviewers()[0];
        self::assertSame(RoundState::Complete, $stored->state());
        self::assertSame(ReviewerState::Blocked, $reviewer->state());
        self::assertSame('Every provider of the tier is out: anthropic keys rejected, openai keys rejected.', $reviewer->failure());
        self::assertFalse($reviewer->owesCatchUp());
        self::assertSame([$reviewer->failure()], $this->payloadValues(LedgerEventKind::ReviewerBlocked, 'reason'));
    }

    public function testALimitHalfwayThroughAReviewStartsItAgainOnTheSubstituteInANewConversation(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $vendor = $this->vendor($this->limitedOnTheSecondRound(), $this->answering());

        try {
            $this->fixture->deliver($message, $vendor);
            self::fail('The substitute continued the review its own provider began.');
        } catch (ReviewerMovesToAnotherAccount) {
        }

        self::assertSame(['anthropic', 'anthropic'], $this->sent);
        $firstConversation = $this->fixture->reviewer($message->reviewerId)->conversationId();
        $this->fixture->deliver($message, $vendor);
        $reviewer = $this->fixture->round($round->id()->toRfc4122())->reviewers()[0];
        self::assertSame(['anthropic', 'anthropic', 'openai'], $this->sent);
        self::assertFalse($firstConversation->equals($reviewer->conversationId()));
        self::assertSame(Provider::OpenAi, $reviewer->substitute());
        self::assertSame(['openai'], $this->payloadValues(LedgerEventKind::CatchUpOwed, 'substitute'));
    }

    public function testALimitHalfwayThroughAReviewRecordsTheSubstitutesModelWithTheOwedCatchUp(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $vendor = $this->vendor($this->limitedOnTheSecondRound(), $this->answering());

        $this->deliverUntilItStays($message, $vendor);

        self::assertSame([RoundFixture::MODEL, RoundFixture::MODEL], $this->payloadValues(LedgerEventKind::ReviewerStarted, 'model'));
        self::assertSame([RoundFixture::SUBSTITUTE_MODEL], $this->payloadValues(LedgerEventKind::CatchUpOwed, 'model'));
    }

    public function testAKeyRejectedAsTheCallIsSentWithNoOtherAccountOrSubstituteEndsTheReviewerFailed(): void
    {
        $round = $this->fixture->start(1);
        $revoked = MockPlatformFactory::createPlatform(static fn (): never => throw new AuthenticationException('revoked'));

        $this->fixture->deliver($this->fixture->bus->messages[0], $this->vendor($revoked, $this->answering(), null));

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(RoundState::Complete, $stored->state());
        self::assertSame(ReviewerState::Failed, $stored->reviewers()[0]->state());
        self::assertSame(ProviderRejectedTheKey::class, $stored->reviewers()[0]->failure());
    }

    // Delivered again as Messenger does after each recoverable move, so the reviewer stays only when a verdict or a block ends it.
    private function deliverUntilItStays(ReviewWithReviewer $message, Vendor $vendor, int $moves = 1): void
    {
        for ($move = 0; $move < $moves; ++$move) {
            try {
                $this->fixture->deliver($message, $vendor);

                return;
            } catch (ReviewerMovesToAnotherAccount) {
            }
        }

        $this->fixture->deliver($message, $vendor);
    }

    // Null maps the tier without substitutes, so the same stack shows what part 1's single provider does when nothing may take over.
    private function vendor(PlatformInterface $own, PlatformInterface $substitute, ?Provider $substituteProvider = Provider::OpenAi): Vendor
    {
        $substitutes = $substituteProvider instanceof Provider ? [['provider' => $substituteProvider->value, 'model' => RoundFixture::SUBSTITUTE_MODEL]] : [];
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['standard' => ['provider' => 'anthropic', 'model' => RoundFixture::MODEL, 'substitutes' => $substitutes]]]], []);
        $accounts = static fn (Provider $provider, PlatformInterface $platform, AccountCooldownWindows $cooldowns): PlatformInterface => new AccountFailoverPlatform($provider, [Account::DEFAULT => $platform], $cooldowns, new NativeClock(), new NullLogger(), new NoPinnedProviderCooldowns());
        $platforms = new ServiceLocator([
            Provider::Anthropic->value => fn (): PlatformInterface => $accounts(Provider::Anthropic, $this->recording(Provider::Anthropic, $own), $this->cooldowns),
            ($substituteProvider ?? Provider::OpenAi)->value => fn (): PlatformInterface => $accounts($substituteProvider ?? Provider::OpenAi, $this->recording($substituteProvider ?? Provider::OpenAi, $substitute), $this->cooldowns),
        ]);
        $tiers = new TierPlatform($resolver, $platforms, $this->cooldowns, new NullLogger());
        $reviewer = new ReviewerAgent(new ReviewerPrompt(new Fence(), new PromptCut(new Fence()), new VerdictParser()), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $servers = self::createStub(ReviewServerAccesses::class);
        $servers->method('forRound')->willReturn(new ReviewServerAccess('intakt-review', 'http://localhost:7780/mcp/review', 'round-credential', ['read_file']));
        $vendor = new SymfonyAiVendor($tiers, $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->fixture->ledger(), $this->fixture->clock, new NullLogger(), StoredSerializerFactory::create()), $this->limit, $this->fixture->clock, $servers);

        return new AccountLimitedVendor($vendor, $resolver, $this->cooldowns);
    }

    private function recording(Provider $provider, PlatformInterface $platform): PlatformInterface
    {
        return new RecordingCalls($platform, function () use ($provider): void {
            $this->sent[] = $provider->value;
        });
    }

    // The first round asks for a file and the second is refused for a limit as it is sent, so the limit comes halfway through the review.
    private function limitedOnTheSecondRound(): PlatformInterface
    {
        $round = 0;

        return MockPlatformFactory::createPlatform(static function () use (&$round): ToolCallResult {
            if (0 === $round++) {
                return new ToolCallResult([new ToolCall('1', 'read_file', ['path' => 'missing.txt'])]);
            }

            throw new RateLimitExceededException(300);
        }, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
    }

    private function answering(string $decision = 'changes'): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(static function () use ($decision): TextResult {
            $answer = new TextResult(VerdictDocument::fromTheDesign()->with('verdict', $decision)->with('resolved', [])->with('findings', 'approve' === $decision ? [] : VerdictDocument::fromTheDesign()->decoded()['findings'])->json());
            $answer->getMetadata()->add('token_usage', new TokenUsage(
                promptTokens: 100,
                completionTokens: 20,
                totalTokens: 120,
                model: RoundFixture::SUBSTITUTE_MODEL,
            ));

            return $answer;
        }, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
    }

    /**
     * @return list<mixed>
     */
    private function payloadValues(LedgerEventKind $kind, string $key): array
    {
        $values = [];

        foreach ($this->fixture->ledger()->readInOrder() as $recorded) {
            if ($kind === $recorded->event->kind) {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($recorded->event->payload, true, flags: JSON_THROW_ON_ERROR);
                $values[] = $payload[$key] ?? null;
            }
        }

        return $values;
    }
}
