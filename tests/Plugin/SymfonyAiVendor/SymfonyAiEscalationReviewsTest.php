<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiEscalationReviews;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TypedProviderFailure;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

#[CoversClass(SymfonyAiEscalationReviews::class)]
#[UsesClass(EscalationVerdict::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(TierRoutedPlatform::class)]
#[UsesClass(TypedProviderFailure::class)]
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

        $this->reviews(static fn (): string => '{"outcome":"rejected","reasons":"Fine."}', $ledger)->escalate('alpha', Uuid::fromString(self::ROUND), $this->messages());

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
        yield 'reasons of form feeds and Unicode spaces' => ['{"outcome":"confirmed","reasons":"\f  ​"}'];
        yield 'reasons of a grapheme joiner and a variation selector' => ['{"outcome":"confirmed","reasons":"͏️"}'];
        yield 'reasons of fillers that render blank' => ['{"outcome":"confirmed","reasons":"ㅤ⠀ᅟ"}'];
        yield 'a property the schema forbids' => ['{"outcome":"confirmed","reasons":"Real.","unexpected":true}'];
        yield 'no reasons' => ['{"outcome":"confirmed"}'];
        yield 'prose' => ['I confirm the finding.'];
    }

    public function testReasonsWithAVisibleCharacterAmongUnicodeSpacesAreAVerdict(): void
    {
        $verdict = $this->reviews(static fn (): string => '{"outcome":"rejected","reasons":" Fine. "}')->escalate('alpha', Uuid::fromString(self::ROUND), $this->messages());

        self::assertSame(EscalationOutcome::Rejected, $verdict?->outcome);
    }

    public function testARateLimitedCallIsThrownAsTheVendorsRateLimitWithItsDelayAndLeftUnsettled(): void
    {
        $ledger = new InMemoryLedger();
        $limit = new RateLimitExceededException(30);

        try {
            $_ = $this->reviews(static fn (): never => throw $limit, $ledger)->escalate('alpha', Uuid::fromString(self::ROUND), $this->messages());
            self::fail('A failed escalation call must stay an error, never a verdict.');
        } catch (ProviderRateLimited $limited) {
            self::assertSame([30, $limit], [$limited->retryAfterSeconds, $limited->getPrevious()]);
        }

        self::assertSame(['started', 'unsettled'], array_column($ledger->payloads(), 'phase'));
    }

    public function testARejectedKeyIsThrownAsTheVendorsRejectedKey(): void
    {
        $this->expectException(ProviderRejectedTheKey::class);

        $this->reviews(static fn (): never => throw new AuthenticationException('Invalid key.'))->escalate('alpha', Uuid::fromString(self::ROUND), $this->messages());
    }

    public function testEveryAccountCoolingDownBeforeTheCallIsThrownAsTheVendorsRateLimitAndReservesNothing(): void
    {
        $ledger = new InMemoryLedger();
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('answering')->willThrowException(new EveryAccountCoolingDown(Provider::Anthropic, 45));

        try {
            $_ = $this->through($tiers, $ledger)->escalate('alpha', Uuid::fromString(self::ROUND), $this->messages());
            self::fail('No account may answer while every account cools down.');
        } catch (ProviderRateLimited $limited) {
            self::assertSame([45, 0], [$limited->retryAfterSeconds, $ledger->count()]);
        }
    }

    public function testTheProvidersRateLimitSurvivesALedgerThatCannotLeaveTheCallUnsettled(): void
    {
        $log = new BufferingLogger();

        try {
            $_ = $this->reviews(static fn (): never => throw new RateLimitExceededException(30), new InMemoryLedger(accepted: 1), log: $log)->escalate('alpha', Uuid::fromString(self::ROUND), $this->messages());
            self::fail('The provider error must reach the queue.');
        } catch (ProviderRateLimited $limited) {
            self::assertSame([30, ['The failed escalation call could not be left unsettled.']], [$limited->retryAfterSeconds, array_column($log->cleanLogs(), 1)]);
        }
    }

    public function testTheLogOfALedgerThatCannotLeaveTheCallUnsettledNamesTheFailureClassWithoutItsExceptionChain(): void
    {
        $log = new BufferingLogger();

        try {
            $_ = $this->reviews(static fn (): never => throw new AuthenticationException('Invalid key sk-ant-abc...xyz.'), new InMemoryLedger(accepted: 1), log: $log)->escalate('alpha', Uuid::fromString(self::ROUND), $this->messages());
            self::fail('The provider error must reach the queue.');
        } catch (ProviderRejectedTheKey) {
            $entry = $log->cleanLogs()[0] ?? null;
            self::assertIsArray($entry);
            $context = $entry[2] ?? null;
            self::assertIsArray($context);
            self::assertSame([['call', 'failure'], []], [array_keys($context), array_filter($context, static fn (mixed $value): bool => $value instanceof Throwable)]);
            self::assertIsString($context['failure']);
            self::assertTrue(class_exists($context['failure']));
        }
    }

    private function reviews(Closure $answer, InMemoryLedger $ledger = new InMemoryLedger(), LoggerInterface $log = new NullLogger()): SymfonyAiEscalationReviews
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => [
            'strong' => ['provider' => 'anthropic', 'model' => 'big'],
            'light' => ['provider' => 'anthropic', 'model' => 'small'],
        ]]], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);

        return $this->through(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), $ledger, $log);
    }

    private function messages(): MessageBag
    {
        return new MessageBag(Message::forSystem('Judge the finding.'), Message::ofUser('The finding.'));
    }

    private function through(TierInvoker $tiers, InMemoryLedger $ledger, LoggerInterface $log = new NullLogger()): SymfonyAiEscalationReviews
    {
        $usage = new UsageLedger($ledger, new MockClock('2026-11-21 12:00:00 UTC'), new NullLogger(), StoredSerializerFactory::create());
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);
        $serializer = self::getContainer()->get(SerializerInterface::class);
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return new SymfonyAiEscalationReviews($tiers, $usage, $limit, $serializer, $log);
    }
}
