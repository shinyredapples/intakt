<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\JobReviewer;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\VerdictParser;
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
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Core\Ai\Infrastructure\FailingWhenRead;
use Intakt\Tests\Core\Ai\Infrastructure\NoPinnedProviderCooldowns;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\WorkerOnSleep;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use LogicException;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

// Runs a round end to end on the real vendor stack down to scripted providers, because a provider-failure case must show the round's end and usage rows an install records (docs/architecture/provider-failure-cases.md).
final class ScriptedRound
{
    // Far more than any scripted case needs, so a round that would never settle fails the test instead of looping.
    private const int MOST_DELIVERIES = 30;
    private const int PARALLEL_CALLS = 3;

    public readonly AccountCooldownWindows $cooldowns;

    /** @var list<ReviewWithReviewer> */
    private array $queue = [];
    private int $taken = 0;
    private int $deliveries = 0;
    private ?Vendor $vendor = null;

    public function __construct(public readonly RoundFixture $fixture, private readonly TestContainer $container)
    {
        $this->cooldowns = new AccountCooldownWindows(new ReadingsInMemory(new NativeClock()), new ArrayAdapter(), new LockFactory(new InMemoryStore()), new NativeClock(), $fixture->ledger(), new NullLogger(), StoredSerializerFactory::create(), [Provider::Anthropic->value => [Account::DEFAULT], Provider::OpenAi->value => [Account::DEFAULT]]);
    }

    // Reviewed through ReviewChange, the use case behind intakt:review, so the decision and the reasons are the ones the console prints.
    public function review(int $reviewers, Vendor $vendor): ChangeReview
    {
        $panel = $this->fixture->commandFor(RoundFixture::CHANGE, $reviewers)->panel;
        $work = function () use ($vendor): void {
            $this->consume($vendor);

            foreach ($this->fixture->judgings() as $judging) {
                $this->fixture->judge(new VerdictPerRole())($judging);
            }
        };
        $request = new ChangeReviewRequest('alpha', __DIR__, RoundFixture::TASK, RoundFixture::DIFF, new RequestedRound(RoundFixture::CHANGE), SampleIdentity::of(RoundFixture::TASK, $panel), $panel);

        // Asked of the scripted judge's vendor, because the real stack here maps only the reviewers' tier and the judge is scripted.
        return $this->reviewChange($work, new VerdictPerRole())->review($request);
    }

    // Each recoverable failure queues its message again at once, as Messenger's retry does once the delay has passed; the cooldowns run on real time, so an account a provider limited stays out for the whole run.
    public function consume(Vendor $vendor): void
    {
        $this->vendor = $vendor;
        $this->queue = [...$this->queue, ...array_slice($this->fixture->bus->messages, $this->taken)];
        $this->taken = count($this->fixture->bus->messages);

        while ([] !== $this->queue) {
            $this->deliverNext();
        }
    }

    // Called from inside a provider call too (see InFlightTogether), so the next reviewer's call goes out while the first one waits for its answer.
    public function deliverNext(): void
    {
        $message = array_shift($this->queue);

        if (!$message instanceof ReviewWithReviewer || !$this->vendor instanceof Vendor) {
            return;
        }

        if (++$this->deliveries > self::MOST_DELIVERIES) {
            throw new LogicException('The round did not settle within '.self::MOST_DELIVERIES.' deliveries.');
        }

        try {
            $this->fixture->deliver($message, $this->vendor);
        } catch (RecoverableExceptionInterface) {
            $this->queue[] = $message;
        }
    }

    // Wired as the container wires it, so the failover, the cooldowns and the limit on parallel calls all take part in each case.
    public function vendor(PlatformInterface $own, PlatformInterface $substitute): Vendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['standard' => ['provider' => 'anthropic', 'model' => 'big', 'substitutes' => [['provider' => 'openai', 'model' => 'other']]]]]], []);
        $platforms = new ServiceLocator([
            Provider::Anthropic->value => fn (): PlatformInterface => $this->accountOf(Provider::Anthropic, $own),
            Provider::OpenAi->value => fn (): PlatformInterface => $this->accountOf(Provider::OpenAi, $substitute),
        ]);
        $tiers = new TierPlatform($resolver, $platforms, $this->cooldowns, new NullLogger());
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), self::PARALLEL_CALLS);
        $usage = new UsageLedger($this->fixture->ledger(), $this->fixture->clock, new NullLogger(), StoredSerializerFactory::create());
        $vendor = new SymfonyAiVendor($tiers, $resolver, $this->reviewer(), new JudgeAgent(new JudgePrompt(new Fence())), $usage, $limit, $this->fixture->clock, $this->container->get(ReviewServerAccesses::class), $this->container->get(JobReviewer::class));

        return new AccountLimitedVendor($vendor, $resolver, $this->cooldowns);
    }

    // Failing when read, because an HTTP provider reports its 429 then, after the next reviewer's call has gone out.
    public function limitedTogether(int $resetInSeconds): InFlightTogether
    {
        return new InFlightTogether($this->deliverNext(...), new FailingWhenRead(new RateLimitExceededException($resetInSeconds)));
    }

    // The first call answers while the next reviewer's call is in flight, so a limit can end a round beside a verdict.
    public function answeringThenLimitedTogether(string $decision, int $resetInSeconds): InFlightTogether
    {
        return new InFlightTogether($this->deliverNext(...), $this->answering($decision), new FailingWhenRead(new RateLimitExceededException($resetInSeconds)));
    }

    public function answering(string $decision): PlatformInterface
    {
        return $this->answeringAfterUnreadable(0, $decision);
    }

    // The first calls answer prose that is no verdict, so a case can show the retry and the failure that follows it.
    public function answeringAfterUnreadable(int $unreadable, string $decision): PlatformInterface
    {
        $calls = 0;

        return MockPlatformFactory::createPlatform(static function () use (&$calls, $unreadable, $decision): TextResult {
            $document = VerdictDocument::fromTheDesign();
            $text = ++$calls <= $unreadable ? 'I approve the first change, but not the second one.' : $document->with('verdict', $decision)->with('resolved', [])->with('findings', 'approve' === $decision ? [] : $document->decoded()['findings'])->json();
            $answer = new TextResult($text);
            $answer->getMetadata()->add('token_usage', new TokenUsage(
                promptTokens: 100,
                completionTokens: 20,
                totalTokens: 120,
                model: 'big',
            ));

            return $answer;
        }, eventDispatcher: ScriptedReviewer::in($this->container)->dispatcher);
    }

    // Each call asks for one more tool round, so a run that stays on this platform is a long conversation without any waiting.
    public function askingForTools(): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(static function (): ToolCallResult {
            $asked = new ToolCallResult([new ToolCall('1', 'read_file', ['path' => 'composer.json'])]);
            $asked->getMetadata()->add('token_usage', new TokenUsage(
                promptTokens: 100,
                completionTokens: 20,
                totalTokens: 120,
                model: 'big',
            ));

            return $asked;
        }, eventDispatcher: ScriptedReviewer::in($this->container)->dispatcher);
    }

    /**
     * @return array<string, list<string>>
     */
    public function usagePhasesByCall(): array
    {
        $phases = [];

        foreach ($this->payloads(LedgerEventKind::UsageRecorded) as $payload) {
            ['call' => $call, 'phase' => $phase] = $payload;
            assert(is_string($call) && is_string($phase));
            $phases[$call][] = $phase;
        }

        return $phases;
    }

    /**
     * @return list<mixed>
     */
    public function payloadValues(LedgerEventKind $kind, string $key): array
    {
        return array_map(static fn (array $payload): mixed => $payload[$key] ?? null, $this->payloads($kind));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payloads(LedgerEventKind $kind): array
    {
        $payloads = [];

        foreach ($this->fixture->ledger()->readInOrder() as $recorded) {
            if ($kind === $recorded->event->kind) {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($recorded->event->payload, true, flags: JSON_THROW_ON_ERROR);
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    private function accountOf(Provider $provider, PlatformInterface $platform): PlatformInterface
    {
        return new AccountFailoverPlatform($provider, [Account::DEFAULT => $platform], $this->cooldowns, new NativeClock(), new NullLogger(), new NoPinnedProviderCooldowns());
    }

    private function reviewer(): ReviewerAgent
    {
        $stopping = new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())));
        $runs = new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), $stopping);

        return new ReviewerAgent(new ReviewerPrompt(new Fence(), new PromptCut(new Fence()), new VerdictParser()), new ReviewerToolboxFactory()->create(...), $runs);
    }

    /**
     * @param Closure(): void $work
     */
    private function reviewChange(Closure $work, Vendor $tiers): ReviewChange
    {
        $serializer = StoredSerializerFactory::create();
        $commands = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RunReviewRound::class => [$this->fixture->startHandlerOn($this->fixture->bus)]]))]);

        return new ReviewChange(
            $commands,
            $this->fixture->transactions(),
            $this->fixture->findingStore(),
            new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices([])),
            new ChangeDecision(),
            new StoredVerdicts($serializer),
            new WorkerOnSleep($this->fixture->clock, $work),
            new FailureInWords(),
            $tiers,
            $this->fixture->noFailedJudgings(),
        );
    }
}
