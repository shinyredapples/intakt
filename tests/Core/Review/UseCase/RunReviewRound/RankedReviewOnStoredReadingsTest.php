<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Ai\Contract\ReviewTools;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ai\Infrastructure\TypedRejectionConverter;
use Intakt\Core\Budget\Contract\AccountPick;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Intakt\Core\Budget\Contract\LeftOutCause;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\AccountRoom;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Core\Budget\Domain\MeasuredRoomRanking;
use Intakt\Core\Budget\Infrastructure\AccountPicked;
use Intakt\Core\Budget\Infrastructure\AccountReadingLock;
use Intakt\Core\Budget\Infrastructure\LedgerAccountPicks;
use Intakt\Core\Budget\Infrastructure\PickedLeftOut;
use Intakt\Core\Budget\Infrastructure\DoctrineHolds;
use Intakt\Core\Budget\Infrastructure\DoctrineMeasuredUse;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Domain\ReviewerState;
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
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerRateLimited;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\Vendor;
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
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

// A review through the real vendor stack whose ranking reads the readings from the install's database, because the in-memory readings of the other ranking tests prove the rule and not the stored path a running install takes.
#[Group('persistence')]
#[CoversClass(AccountFailoverPlatform::class)]
#[CoversClass(MeasuredRoomRanking::class)]
#[UsesClass(CeilingStartGuard::class)]
#[UsesClass(AccountRoom::class)]
#[UsesClass(RankedAccounts::class)]
#[UsesClass(LeftOutAccount::class)]
#[UsesClass(LedgerAccountPicks::class)]
#[UsesClass(AccountPicked::class)]
#[UsesClass(PickedLeftOut::class)]
#[UsesClass(AccountPick::class)]
#[UsesClass(DoctrineMeasuredUse::class)]
#[UsesClass(DoctrineHolds::class)]
#[UsesClass(AccountReadingLock::class)]
#[UsesClass(Usage::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowUse::class)]
#[UsesClass(ReviewWithReviewerHandler::class)]
#[UsesClass(ReviewerLeases::class)]
#[UsesClass(ReviewerRateLimited::class)]
#[UsesClass(AccountLimitedVendor::class)]
#[UsesClass(SymfonyAiVendor::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(ProviderRateLimited::class)]
#[UsesClass(AccountCooldownWindows::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(TypedRejectionConverter::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(Account::class)]
final class RankedReviewOnStoredReadingsTest extends KernelTestCase
{
    private RoundFixture $fixture;
    private DoctrineMeasuredUse $measured;
    /** @var list<string> */
    private array $called = [];

    public function testAReviewWhoseFirstAccountIsAtItsStoredWeeklyCeilingGetsItsVerdictOnTheSecondWithNoRefusedCallFirst(): void
    {
        $this->store(Account::DEFAULT, 95.0, '+2 days');
        $this->store('team', 20.0, '+2 days');
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];

        $this->fixture->deliver($message, $this->vendorOnTwoAccounts());

        self::assertSame(ReviewerState::Verdict, $this->fixture->reviewer($message->reviewerId)->state());
        self::assertSame(['team'], array_values(array_unique($this->called)));
        self::assertNotContains(LedgerEventKind::AccountCooledDown, $this->fixture->ledgerKinds());
        self::assertEquals(2, $this->fixture->entities->getConnection()->fetchOne('SELECT COUNT(*) FROM budget_window_reading'));
        $pick = $this->picks()->latestOf(Provider::ClaudeCode);
        self::assertEquals(new Account(Provider::ClaudeCode, 'team'), $pick?->picked);
        self::assertEquals([new LeftOutAccount(new Account(Provider::ClaudeCode, Account::DEFAULT), LeftOutCause::AtWeeklyCeiling, $this->fixture->clock->now()->modify('+2 days'))], $pick->leftOut);
    }

    public function testAReviewWhoseEveryAccountIsAtAStoredCeilingSendsNoCallAndWaitsForTheEarliestReset(): void
    {
        $this->store(Account::DEFAULT, 95.0, '+2 days');
        $this->store('team', 95.0, '+3 hours');
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];

        try {
            $this->fixture->deliver($message, $this->vendorOnTwoAccounts());
            self::fail('A review started though every account is at a ceiling.');
        } catch (ReviewerRateLimited $wait) {
            self::assertSame(3 * 3600 * 1000, $wait->getRetryDelay());
        }

        self::assertSame([], $this->called);
        self::assertSame(ReviewerState::Queued, $this->fixture->reviewer($message->reviewerId)->state());
        $pick = $this->picks()->latestOf(Provider::ClaudeCode);
        self::assertNotNull($pick);
        self::assertNull($pick->picked);
        self::assertEquals($this->fixture->clock->now()->modify('+3 hours'), $pick->earliestReset());
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->measured = new DoctrineMeasuredUse($this->fixture->entities, $this->fixture->clock, new EventDispatcher(), new AccountReadingLock(new LockFactory(new InMemoryStore())));
        $this->called = [];
    }

    private function store(string $name, float $weeklyPercent, string $resetsIn): void
    {
        $reading = new WindowReading(new Account(Provider::ClaudeCode, $name), UsageWindow::Weekly, new Usage($weeklyPercent, $this->fixture->clock->now()->modify($resetsIn)));

        self::assertSame(ReadingOutcome::Recorded, $this->measured->record($reading));
    }

    private function picks(): LedgerAccountPicks
    {
        return new LedgerAccountPicks($this->fixture->ledger(), $this->fixture->ledgerPayloads(), StoredSerializerFactory::create(), new NullLogger());
    }

    private function vendorOnTwoAccounts(): Vendor
    {
        $clock = $this->fixture->clock;
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['standard' => ['provider' => 'claude_code', 'model' => 'big']]]], []);
        $cooldowns = new AccountCooldownWindows(new ReadingsInMemory($clock), new ArrayAdapter(), new LockFactory(new InMemoryStore()), $clock, $this->fixture->ledger(), new NullLogger(), StoredSerializerFactory::create(), [Provider::ClaudeCode->value => [Account::DEFAULT, 'team']]);
        $ranking = new MeasuredRoomRanking(new DoctrineHolds($this->fixture->entities, $clock), new CeilingStartGuard($this->measured, $clock, ['five-hour' => 90.0, 'weekly' => 90.0]), $this->picks());
        $platforms = [Account::DEFAULT => $this->answering(Account::DEFAULT), 'team' => $this->answering('team')];
        $accounts = new AccountFailoverPlatform(Provider::ClaudeCode, $platforms, $cooldowns, $clock, new NullLogger(), new NoPinnedProviderCooldowns(), $ranking);
        $tiers = new TierPlatform($resolver, new ServiceLocator([Provider::ClaudeCode->value => static fn (): PlatformInterface => $accounts]), $cooldowns, new NullLogger());
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 3);
        $servers = self::createStub(ReviewServerAccesses::class);
        $servers->method('forRound')->willReturn(new ReviewServerAccess('intakt-review', 'http://localhost:7780/mcp/review', 'round-credential', new ReviewTools('read_file')));
        $vendor = new SymfonyAiVendor($tiers, $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->fixture->ledger(), $clock, new NullLogger(), StoredSerializerFactory::create()), $limit, $clock, $servers);

        return new AccountLimitedVendor($vendor, $resolver, $cooldowns);
    }

    private function answering(string $name): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(function () use ($name): TextResult {
            $this->called[] = $name;
            $answer = new TextResult(VerdictDocument::fromTheDesign()->with('resolved', [])->json());
            $answer->getMetadata()->add('token_usage', new TokenUsage(
                promptTokens: 100,
                completionTokens: 20,
                totalTokens: 120,
                model: 'big',
            ));

            return $answer;
        }, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
    }
}
