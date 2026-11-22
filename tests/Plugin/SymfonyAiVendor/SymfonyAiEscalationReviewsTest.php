<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiEscalationReviews;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SymfonyAiEscalationReviews::class)]
#[UsesClass(EscalationVerdict::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(TierRoutedPlatform::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(UsageReservation::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
final class SymfonyAiEscalationReviewsTest extends KernelTestCase
{
    private const string ROUND = '0192a000-0000-7000-8000-000000000002';

    public function testTheEscalationCallGoesToTheStrongTiersModelWithNoToolsAndReturnsItsVerdict(): void
    {
        $calls = [];
        $answer = static function (Model $model, mixed $input, array $options = []) use (&$calls): string {
            $calls[] = [$model->getName(), array_key_exists('tools', $options)];

            return '{"outcome":"confirmed","reasons":"Real."}';
        };

        $verdict = $this->reviews($answer)->escalate('alpha', Uuid::fromString(self::ROUND), $this->messages());

        self::assertEquals([new EscalationVerdict(EscalationOutcome::Confirmed, 'Real.'), [['big', false]]], [$verdict, $calls]);
    }

    public function testTheCallIsRecordedOnTheStrongTierWithTheRoundAndSettled(): void
    {
        $ledger = new InMemoryLedger();

        $_ = $this->reviews(static fn (): string => '{"outcome":"rejected","reasons":"Fine."}', $ledger)->escalate('alpha', Uuid::fromString(self::ROUND), $this->messages());

        self::assertSame([['started', 'settled'], Tier::Strong->value, self::ROUND], [array_column($ledger->payloads(), 'phase'), $ledger->payloads()[0]['tier'] ?? null, $ledger->payloads()[0]['round'] ?? null]);
    }

    #[DataProvider('answersThatAreNoVerdict')]
    public function testAnAnswerThatDoesNotFitTheSchemaIsNoVerdict(string $answer): void
    {
        self::assertNull($this->reviews(static fn (): string => $answer)->escalate('alpha', Uuid::fromString(self::ROUND), $this->messages()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function answersThatAreNoVerdict(): iterable
    {
        yield 'an unknown outcome' => ['{"outcome":"maybe","reasons":"Unsure."}'];
        yield 'blank reasons' => ['{"outcome":"confirmed","reasons":"  "}'];
        yield 'prose' => ['I confirm the finding.'];
    }

    public function testAFailedCallIsThrownUnchangedAndLeftUnsettled(): void
    {
        $ledger = new InMemoryLedger();

        try {
            $_ = $this->reviews(static fn (): never => throw new RateLimitExceededException(30), $ledger)->escalate('alpha', Uuid::fromString(self::ROUND), $this->messages());
            self::fail('A failed escalation call must stay an error, never a verdict.');
        } catch (RateLimitExceededException $limited) {
            self::assertSame(30, $limited->getRetryAfter());
        }

        self::assertSame(['started', 'unsettled'], array_column($ledger->payloads(), 'phase'));
    }

    private function messages(): MessageBag
    {
        return new MessageBag(Message::forSystem('Judge the finding.'), Message::ofUser('The finding.'));
    }

    private function reviews(Closure $answer, InMemoryLedger $ledger = new InMemoryLedger()): SymfonyAiEscalationReviews
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => [
            'strong' => ['provider' => 'anthropic', 'model' => 'big'],
            'light' => ['provider' => 'anthropic', 'model' => 'small'],
        ]]], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $usage = new UsageLedger($ledger, new MockClock('2026-11-21 12:00:00 UTC'), new NullLogger(), StoredSerializerFactory::create());
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiEscalationReviews(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $usage, $limit);
    }
}
