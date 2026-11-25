<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangesWithoutFindings;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\SampleProjectData;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
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
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Review\Contract\RecordedAnswer;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
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
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Yaml\Yaml;

// The sample's first review runs the team intakt:sample:seed writes (correctness on the strong tier, security on the standard tier), so the rounds start on the panel read back from that written configuration; the answers follow the order the round delivers them.
#[Group('persistence')]
#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(Verdict::class)]
#[UsesClass(ChangesWithoutFindings::class)]
#[UsesClass(ReviewWithReviewerHandler::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(ReviewerLeases::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(SampleProject::class)]
#[UsesClass(SampleProjectData::class)]
#[UsesClass(ConfiguredPanels::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(ReviewerRole::class)]
final class FirstReviewOfTwoReviewersTest extends KernelTestCase
{
    private RoundFixture $fixture;

    private string $configuration;

    /** @var array{tiers: array<string, array{provider: string, model: non-empty-string}>, review: array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}} */
    private array $seeded;

    private int $answered = 0;

    /** @var list<string> */
    private array $calls = [];

    public function testTheSeededPanelPutsCorrectnessAndSecurityOnTheirOwnTierAndModel(): void
    {
        $this->deliverAll([RecordedAnswer::APPROVE, RecordedAnswer::APPROVE]);

        self::assertSame([['correctness', 'strong'], ['security', 'standard']], array_map(static fn (array $reviewer): array => [$reviewer['role'], $reviewer['tier']], $this->seeded['review']['reviewers']));
        self::assertSame([$this->modelOf('strong'), $this->modelOf('standard')], $this->calls);
        self::assertNotSame($this->modelOf('strong'), $this->modelOf('standard'));
    }

    public function testCorrectnessAnsweringAVerdictOnlyAndThenAFindingShowsBothSeededReviewersWithAVerdict(): void
    {
        $this->deliverAll([RecordedAnswer::VERDICT_ONLY_CHANGES, RecordedAnswer::RETRY_WITH_A_FINDING, RecordedAnswer::APPROVE]);

        self::assertSame([ReviewerState::Verdict, ReviewerState::Verdict], $this->states());
        self::assertSame([['changes', 1], ['approve', 0]], $this->verdicts());
        self::assertSame([$this->modelOf('strong'), $this->modelOf('strong'), $this->modelOf('standard')], $this->calls);
    }

    public function testCorrectnessAnsweringBlockedWithoutAFindingOnBothTriesFailsAndSecurityStillGivesItsVerdict(): void
    {
        $this->deliverAll([RecordedAnswer::VERDICT_ONLY_BLOCKED, RecordedAnswer::VERDICT_ONLY_BLOCKED, RecordedAnswer::APPROVE]);

        self::assertSame([ReviewerState::Failed, ReviewerState::Verdict], $this->states());
        self::assertSame([['approve', 0]], $this->verdicts());
    }

    public function testCorrectnessWhoseSecondAnswerIsAnApprovalWithoutFindingsGivesThatVerdict(): void
    {
        $this->deliverAll([RecordedAnswer::VERDICT_ONLY_CHANGES, RecordedAnswer::APPROVE, RecordedAnswer::APPROVE]);

        self::assertSame([ReviewerState::Verdict, ReviewerState::Verdict], $this->states());
        self::assertSame([['approve', 0], ['approve', 0]], $this->verdicts());
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->configuration = sys_get_temp_dir().'/intakt-sample-'.bin2hex(random_bytes(6)).'.yaml';
        $this->seeded = $this->seedTheSampleProject();
        $this->fixture->startOnPanel(new ConfiguredPanels(['alpha' => $this->seeded['review']], [])->panelFor('alpha'));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->configuration);
        parent::tearDown();
    }

    /**
     * @param list<string> $answers
     */
    private function deliverAll(array $answers): void
    {
        $vendor = $this->vendor($answers);

        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }
    }

    /**
     * @param list<string> $answers
     */
    private function vendor(array $answers): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => $this->seeded['tiers']]], []);
        $platforms = [];
        foreach ([Provider::Anthropic, Provider::OpenAi] as $provider) {
            $platform = $this->platformAnswering($provider, $answers);
            $platforms[$provider->value] = static fn (): PlatformInterface => $platform;
        }

        $tiers = new TierPlatform($resolver, new ServiceLocator($platforms), new NoCooldowns(), new NullLogger());
        $agent = new ReviewerAgent(RemembersNothing::prompt(), new ReviewerToolboxFactory()->create(...), new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 2);

        return new SymfonyAiVendor($tiers, $resolver, $agent, new JudgeAgent(new JudgePrompt(new Fence())), new UsageLedger($this->fixture->ledger(), $this->fixture->clock, new NullLogger(), StoredSerializerFactory::create()), $limit, $this->fixture->clock, self::createStub(ReviewServerAccesses::class));
    }

    /**
     * @param list<string> $answers
     */
    private function platformAnswering(Provider $provider, array $answers): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(
            function (Model $model) use ($provider, $answers): string {
                $this->calls[] = $provider->value.' '.$model->getName();

                return RecordedAnswer::named($answers[$this->answered++]);
            },
            eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher,
        );
    }

    private function modelOf(string $tier): string
    {
        return $this->seeded['tiers'][$tier]['provider'].' '.$this->seeded['tiers'][$tier]['model'];
    }

    /**
     * @return list<ReviewerState>
     */
    private function states(): array
    {
        return array_map(fn ($message): ReviewerState => $this->fixture->reviewer($message->reviewerId)->state(), $this->fixture->bus->messages);
    }

    /**
     * @return list<array{mixed, mixed}>
     */
    private function verdicts(): array
    {
        $verdicts = [];

        foreach ($this->fixture->ledger()->readInOrder() as $recorded) {
            if (LedgerEventKind::VerdictReceived === $recorded->event->kind) {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($recorded->event->payload, true, flags: JSON_THROW_ON_ERROR);
                $verdicts[] = [$payload['decision'] ?? null, $payload['findings'] ?? null];
            }
        }

        return $verdicts;
    }

    /**
     * @return array{tiers: array<string, array{provider: string, model: non-empty-string}>, review: array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}}
     */
    private function seedTheSampleProject(): array
    {
        $keyed = [Provider::Anthropic, Provider::OpenAi];
        $accounts = self::createStub(EnabledAccounts::class);
        $accounts->method('of')->willReturnCallback(static fn (Provider $provider): array => in_array($provider, $keyed, true) ? [new Account($provider, Account::DEFAULT)] : []);
        $sample = new SampleProject($accounts, null);
        self::assertSame($keyed, $sample->keyedProviders());
        $sample->write($this->configuration, $keyed);

        /** @var array{projects: array<string, array{tiers: array<string, array{provider: string, model: non-empty-string}>, review: array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}}>} $written */
        $written = Yaml::parseFile($this->configuration);

        return $written['projects'][SampleProject::NAME];
    }
}
