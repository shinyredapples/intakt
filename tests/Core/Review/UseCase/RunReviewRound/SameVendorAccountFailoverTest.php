<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
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
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\JudgeAgent;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMovesToAnotherAccount;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerRateLimited;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Intakt\Tests\Core\Ai\Infrastructure\FailingWhenRead;
use Intakt\Tests\Core\Ai\Infrastructure\NoPinnedProviderCooldowns;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\Infrastructure\RemembersNothing;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

// Through the real vendor stack down to two in-memory providers, so the move is proven end to end and not only at one layer; the first account answers with a 429.
#[Group('persistence')]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(ReviewerMovesToAnotherAccount::class)]
#[CoversClass(AccountLimitedVendor::class)]
#[CoversClass(AccountFailoverPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[CoversClass(AccountPlatform::class)]
#[CoversClass(AccountCooldownWindows::class)]
#[UsesClass(ReviewerLeases::class)]
#[UsesClass(SymfonyAiVendor::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(TypedRejectionConverter::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(VendorAccountOut::class)]
#[UsesClass(ProviderFailedTransiently::class)]
#[UsesClass(ProviderRateLimited::class)]
#[UsesClass(ReviewerRateLimited::class)]
#[UsesClass(Account::class)]
final class SameVendorAccountFailoverTest extends KernelTestCase
{
    private RoundFixture $fixture;
    private AccountCooldownWindows $cooldowns;

    public function testAReviewerWhoseAccountIsOutIsQueuedAgainForAnImmediateRetry(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];

        try {
            $this->fixture->deliver($message, new VerdictPerRole(failure: new VendorAccountOut(new RuntimeException('429'))));
            self::fail('The move to another account was recorded as a failure.');
        } catch (ReviewerMovesToAnotherAccount $retry) {
            self::assertSame(0, $retry->getRetryDelay());
        }

        self::assertSame(ReviewerState::Queued, $this->fixture->reviewer($message->reviewerId)->state());
    }

    public function testAMoveToAnotherAccountDoesNotUseUpTheOneRetryOfAStoppedWorker(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];

        try {
            $this->fixture->deliver($message, new VerdictPerRole(failure: new VendorAccountOut(new RuntimeException('429'))));
        } catch (ReviewerMovesToAnotherAccount) {
        }

        self::assertFalse($this->fixture->reviewer($message->reviewerId)->hasRestarted());
    }

    public function testARoundWithOneOfTwoAccountsOutMovesTheReviewerToTheOtherAccountAndOwesNoCatchUp(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $vendor = $this->vendorOnTwoAccounts();

        $this->deliverUntilItStays($message, $vendor);

        $stored = $this->fixture->round($round->id()->toRfc4122());
        self::assertSame(RoundState::Complete, $stored->state());
        self::assertSame(ReviewerState::Verdict, $stored->reviewers()[0]->state());
        self::assertSame('role-1', $stored->reviewers()[0]->roleName());
        self::assertSame([
            LedgerEventKind::ReviewStarted,
            LedgerEventKind::ReviewerStarted,
            LedgerEventKind::UsageRecorded,
            LedgerEventKind::AccountCooledDown,
            LedgerEventKind::UsageRecorded,
            LedgerEventKind::ReviewerStarted,
            LedgerEventKind::UsageRecorded,
            LedgerEventKind::UsageRecorded,
            LedgerEventKind::VerdictReceived,
            LedgerEventKind::ReviewerFindingsRecorded,
        ], $this->fixture->ledgerKinds());
        self::assertSame(['anthropic.default'], $this->payloadValues(LedgerEventKind::AccountCooledDown, 'account'));
        self::assertSame(['anthropic.team'], $this->usageAccounts());
    }

    public function testAReviewerWhoseLastFreeAccountFailsTransientlyWaitsForTheSharedCooldownInsteadOfFailing(): void
    {
        $this->cooldowns->coolDown(new Account(Provider::Anthropic, Account::DEFAULT), 60);
        $this->cooldowns->coolDown(new Account(Provider::Anthropic, 'team'), 60);
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['standard' => ['provider' => 'anthropic', 'model' => 'big']]]], []);
        $vendor = new AccountLimitedVendor(new VerdictPerRole(failure: new ProviderFailedTransiently(new ServerException(503))), $resolver, $this->cooldowns);

        try {
            $this->fixture->deliver($message, $vendor);
            self::fail('The transient failure was recorded as the reviewer\'s end.');
        } catch (ReviewerRateLimited $wait) {
            self::assertGreaterThan(0, $wait->getRetryDelay());
        }

        self::assertSame(ReviewerState::Queued, $this->fixture->reviewer($message->reviewerId)->state());
    }

    public function testAnotherReviewerOnTheCooledAccountGoesStraightToTheOtherAccount(): void
    {
        $this->fixture->start(2);
        [$first, $second] = $this->fixture->bus->messages;
        $vendor = $this->vendorOnTwoAccounts();

        $this->deliverUntilItStays($first, $vendor);
        $this->fixture->deliver($second, $vendor);

        self::assertSame(ReviewerState::Verdict, $this->fixture->reviewer($second->reviewerId)->state());
        self::assertCount(1, $this->payloadValues(LedgerEventKind::AccountCooledDown, 'account'));
        self::assertSame(['anthropic.team', 'anthropic.team'], $this->usageAccounts());
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->cooldowns = new AccountCooldownWindows(new ReadingsInMemory(new NativeClock()), new ArrayAdapter(), new LockFactory(new InMemoryStore()), new NativeClock(), $this->fixture->ledger(), new NullLogger(), StoredSerializerFactory::create(), [Provider::Anthropic->value => [Account::DEFAULT, 'team']]);
    }

    private function vendorOnTwoAccounts(): Vendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['standard' => ['provider' => 'anthropic', 'model' => 'big']]]], []);
        $team = MockPlatformFactory::createPlatform(static function (): TextResult {
            $answer = new TextResult(VerdictDocument::fromTheDesign()->with('resolved', [])->json());
            $answer->getMetadata()->add('token_usage', new TokenUsage(
                promptTokens: 100,
                completionTokens: 20,
                totalTokens: 120,
                model: 'big',
            ));

            return $answer;
        }, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $platforms = [Account::DEFAULT => new FailingWhenRead(new RateLimitExceededException(300)), 'team' => $team];
        $accounts = new AccountFailoverPlatform(Provider::Anthropic, $platforms, $this->cooldowns, new NativeClock(), new NullLogger(), new NoPinnedProviderCooldowns());
        $tiers = new TierPlatform($resolver, new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $accounts]), $this->cooldowns, new NullLogger());
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 3);
        $vendor = new SymfonyAiVendor($tiers, $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->fixture->ledger(), $this->fixture->clock, new NullLogger(), StoredSerializerFactory::create()), $limit, $this->fixture->clock, self::createStub(ReviewServerAccesses::class));

        return new AccountLimitedVendor($vendor, $resolver, $this->cooldowns);
    }

    // Delivered again as Messenger does after the recoverable move, so the retry runs on the next account.
    private function deliverUntilItStays(ReviewWithReviewer $message, Vendor $vendor): void
    {
        try {
            $this->fixture->deliver($message, $vendor);
            self::fail('The limited account gave a verdict.');
        } catch (ReviewerMovesToAnotherAccount) {
        }

        $this->fixture->deliver($message, $vendor);
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

    /**
     * @return list<mixed>
     */
    private function usageAccounts(): array
    {
        $accounts = [];

        foreach ($this->payloadValues(LedgerEventKind::UsageRecorded, 'usages') as $usages) {
            foreach ((array) $usages as $usage) {
                $accounts[] = ((array) $usage)['account'] ?? null;
            }
        }

        return $accounts;
    }
}
