<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ProviderFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\ProviderCandidate;
use Intakt\Core\Ai\Infrastructure\FailoverTrail;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMoves;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
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
use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Intakt\Tests\Core\Ai\Infrastructure\FailingWhenRead;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

// Through the real vendor stack down to in-memory providers: the tier's own provider answers with a revoked key, and the next provider is its configured substitute.
#[Group('persistence')]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(AccountLimitedVendor::class)]
#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(ProviderFailoverPlatform::class)]
#[CoversClass(RoundReviewer::class)]
#[CoversClass(RoundLedger::class)]
#[UsesClass(ReviewerLeases::class)]
#[UsesClass(ReviewerMoves::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(NoProviderLeft::class)]
#[UsesClass(ProviderRejectedTheKey::class)]
#[UsesClass(VendorAccountOut::class)]
#[UsesClass(VendorReview::class)]
#[UsesClass(ProviderCandidate::class)]
#[UsesClass(FailoverTrail::class)]
#[UsesClass(EveryProviderOut::class)]
#[UsesClass(AccountFailoverPlatform::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(AccountCooldownWindows::class)]
#[UsesClass(Account::class)]
final class CrossProviderSubstituteTest extends KernelTestCase
{
    private RoundFixture $fixture;
    private AccountCooldownWindows $cooldowns;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->cooldowns = new AccountCooldownWindows(new ArrayAdapter(), new LockFactory(new InMemoryStore()), new NativeClock(), $this->fixture->ledger(), new NullLogger(), [Provider::Anthropic->value => [Account::DEFAULT], Provider::OpenAi->value => [Account::DEFAULT], Provider::ClaudeCode->value => [Account::DEFAULT]]);
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
        self::assertStringStartsWith('Every provider of the tier is out: anthropic for ', (string) $reviewer->failure());
        self::assertStringContainsString(', openai for ', (string) $reviewer->failure());
        self::assertFalse($reviewer->owesCatchUp());
        self::assertSame([(string) $reviewer->failure()], $this->payloadValues(LedgerEventKind::ReviewerBlocked, 'reason'));
    }

    // Delivered again as Messenger does after each recoverable move, until the reviewer stays.
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

    private function vendor(PlatformInterface $own, PlatformInterface $substitute, Provider $substituteProvider = Provider::OpenAi): Vendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['standard' => ['provider' => 'anthropic', 'model' => 'big', 'substitutes' => [['provider' => $substituteProvider->value, 'model' => 'other']]]]]], []);
        $accounts = static fn (Provider $provider, PlatformInterface $platform, AccountCooldownWindows $cooldowns): PlatformInterface => new AccountFailoverPlatform($provider, [Account::DEFAULT => $platform], $cooldowns, new NativeClock(), new NullLogger());
        $platforms = new ServiceLocator([
            Provider::Anthropic->value => fn (): PlatformInterface => $accounts(Provider::Anthropic, $own, $this->cooldowns),
            $substituteProvider->value => fn (): PlatformInterface => $accounts($substituteProvider, $substitute, $this->cooldowns),
        ]);
        $tiers = new TierPlatform($resolver, new ProviderFailoverPlatform($platforms, $this->cooldowns, new NativeClock(), new NullLogger()));
        $reviewer = new ReviewerAgent(new ReviewerPrompt(new Fence(), new VerdictParser()), new ReviewerToolboxFactory()->create(...), new InMemoryMessageStore());
        $vendor = new SymfonyAiVendor($tiers, $resolver, $reviewer, new UsageLedger($this->fixture->ledger(), $this->fixture->clock, new NullLogger()));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 3);

        return new AccountLimitedVendor($vendor, $resolver, $limit, $this->cooldowns);
    }

    private function answering(string $decision = 'changes'): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(static function () use ($decision): TextResult {
            $answer = new TextResult(VerdictDocument::fromTheDesign()->with('verdict', $decision)->with('findings', 'approve' === $decision ? [] : VerdictDocument::fromTheDesign()->decoded()['findings'])->json());
            $answer->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 100, completionTokens: 20, totalTokens: 120, model: 'other'));

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
