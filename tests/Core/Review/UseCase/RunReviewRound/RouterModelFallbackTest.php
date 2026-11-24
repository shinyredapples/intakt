<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Ai\Contract\ReviewTools;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\PinnedProviderCooldownWindows;
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
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Intakt\Plugin\Vendor\Contract\VendorReview;
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
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

// Through the real vendor stack down to an in-memory router, so the fallback is proven end to end (issues #2825 and #2816): the tier's pinned router provider answers HTTP 429, and the same reviewer starts again at once on the pinned fallback of the same provider.
#[Group('persistence')]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(AccountLimitedVendor::class)]
#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(TierPlatform::class)]
#[CoversClass(AccountPlatform::class)]
#[UsesClass(RoundReviewer::class)]
#[UsesClass(RoundLedger::class)]
#[UsesClass(ReviewerLeases::class)]
#[UsesClass(ReviewerMoves::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(VendorAccountOut::class)]
#[UsesClass(VendorReview::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(TypedRejectionConverter::class)]
#[UsesClass(AccountFailoverPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(AccountCooldownWindows::class)]
#[UsesClass(PinnedProviderCooldownWindows::class)]
#[UsesClass(PinnedProvider::class)]
#[UsesClass(Account::class)]
final class RouterModelFallbackTest extends KernelTestCase
{
    private const string PINNED = 'moonshotai/Kimi-K3.1-Pro:deepinfra';

    private const string FALLBACK = 'moonshotai/Kimi-K3.1-Pro:together';

    private const string OTHER_MODEL = 'deepseek-ai/DeepSeek-V4:novita';

    private RoundFixture $fixture;

    private AccountCooldownWindows $cooldowns;

    private PinnedProviderCooldownWindows $pins;

    /**
     * @var list<string>
     */
    private array $sent = [];

    public function testARouter429MovesTheReviewerAtOnceToThePinnedFallbackWhichCompletesTheRound(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $vendor = $this->vendor();

        try {
            $this->fixture->deliver($message, $vendor);
            self::fail('The rate-limited reviewer waited instead of moving to the pinned fallback.');
        } catch (ReviewerMovesToAnotherAccount $moved) {
            self::assertSame(0, $moved->getRetryDelay());
        }

        $this->fixture->deliver($message, $vendor);

        $stored = $this->fixture->round($round->id()->toRfc4122());
        $reviewer = $stored->reviewers()[0];
        self::assertSame([self::PINNED, self::FALLBACK], $this->sent);
        self::assertSame(RoundState::Complete, $stored->state());
        self::assertSame(ReviewerState::Verdict, $reviewer->state());
        self::assertNull($reviewer->substitute());
        self::assertFalse($reviewer->owesCatchUp());
        self::assertSame(60, $this->pins->secondsUntilFree(new PinnedProvider(new Account(Provider::OpenAiCompatible, Account::DEFAULT), 'deepinfra')));
        self::assertSame(0, $this->cooldowns->secondsUntilAnAccountIsFree(Provider::OpenAiCompatible));
    }

    // Maintainer decision 3 of issue #2861: another model on the own provider is a substitute, so its verdict owes a catch-up recorded under that model, even when a pinned fallback of the same model is listed before it on that provider.
    public function testAnotherModelOnTheOwnProviderAnsweringAfterThePinned429OwesACatchUpUnderItsModel(): void
    {
        $round = $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $this->pins->coolDown(new PinnedProvider(new Account(Provider::OpenAiCompatible, Account::DEFAULT), 'together'), 600);
        $vendor = $this->vendor([self::FALLBACK, self::OTHER_MODEL]);

        try {
            $this->fixture->deliver($message, $vendor);
        } catch (ReviewerMovesToAnotherAccount) {
        }

        $this->fixture->deliver($message, $vendor);

        $reviewer = $this->fixture->round($round->id()->toRfc4122())->reviewers()[0];
        self::assertSame([self::PINNED, self::OTHER_MODEL], $this->sent);
        self::assertSame([Provider::OpenAiCompatible, true], [$reviewer->substitute(), $reviewer->owesCatchUp()]);
        self::assertSame([self::OTHER_MODEL], $this->catchUpModels());
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        // The fixture's stopped clock, because a slow database engine could otherwise let a second pass before the pin's wait is read.
        $this->cooldowns = new AccountCooldownWindows(new ReadingsInMemory($this->fixture->clock), new ArrayAdapter(), new LockFactory(new InMemoryStore()), $this->fixture->clock, $this->fixture->ledger(), new NullLogger(), StoredSerializerFactory::create(), [Provider::OpenAiCompatible->value => [Account::DEFAULT]]);
        $this->pins = new PinnedProviderCooldownWindows(new ArrayAdapter(), $this->fixture->clock, new LockFactory(new InMemoryStore()));
    }

    /**
     * @return list<mixed>
     */
    private function catchUpModels(): array
    {
        $models = [];
        foreach ($this->fixture->ledger()->readInOrder() as $recorded) {
            if (LedgerEventKind::CatchUpOwed === $recorded->event->kind) {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($recorded->event->payload, true, flags: JSON_THROW_ON_ERROR);
                $models[] = $payload['model'] ?? null;
            }
        }

        return $models;
    }

    /**
     * @param list<non-empty-string> $substitutes
     */
    private function vendor(array $substitutes = [self::FALLBACK]): Vendor
    {
        $listed = array_map(static fn (string $model): array => ['provider' => Provider::OpenAiCompatible->value, 'model' => $model], $substitutes);
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['standard' => ['provider' => Provider::OpenAiCompatible->value, 'model' => self::PINNED, 'substitutes' => $listed]]]], []);
        $router = new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $this->router()], $this->cooldowns, $this->fixture->clock, new NullLogger(), $this->pins);
        $tiers = new TierPlatform($resolver, new ServiceLocator([Provider::OpenAiCompatible->value => static fn (): PlatformInterface => $router]), $this->cooldowns, new NullLogger());
        $reviewer = new ReviewerAgent(new ReviewerPrompt(new Fence(), new PromptCut(new Fence()), new VerdictParser()), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $servers = self::createStub(ReviewServerAccesses::class);
        $servers->method('forRound')->willReturn(new ReviewServerAccess('intakt-review', 'http://localhost:7780/mcp/review', 'round-credential', new ReviewTools('read_file')));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);
        $vendor = new SymfonyAiVendor($tiers, $resolver, $reviewer, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->fixture->ledger(), $this->fixture->clock, new NullLogger(), StoredSerializerFactory::create()), $limit, $this->fixture->clock, $servers);

        return new AccountLimitedVendor($vendor, $resolver, $this->cooldowns);
    }

    // The pinned router provider is rate-limited upstream and the fallback answers, as on 2026-11-18 (issue #2790).
    private function router(): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(function (string|Model $model): TextResult {
            $name = $model instanceof Model ? $model->getName() : $model;
            $this->sent[] = $name;
            if (self::PINNED === $name) {
                throw new RateLimitExceededException();
            }

            $answer = new TextResult(VerdictDocument::fromTheDesign()->with('verdict', 'approve')->with('resolved', [])->with('findings', [])->json());
            $answer->getMetadata()->add('token_usage', new TokenUsage(
                promptTokens: 100,
                completionTokens: 20,
                totalTokens: 120,
                model: $name,
            ));

            return $answer;
        }, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
    }
}
