<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Build\Contract\ReadinessGap;
use Intakt\Core\Build\Contract\SizeTier;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Infrastructure\SizingAgent;
use Intakt\Core\Build\Infrastructure\SizingPrompt;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\SizeTask\EligibleTasks;
use Intakt\Core\Build\UseCase\SizeTask\SizeTask;
use Intakt\Core\Build\UseCase\SizeTask\SizingOutcome;
use Intakt\Core\Build\UseCase\SizeTask\SizingRecords;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectEligibilityLabel;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiSizingVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

// The whole path of one sample task with the real vendor, agent and parking over a scripted platform, so the verdict and its record show together.
#[CoversClass(SizeTask::class)]
#[UsesClass(SymfonyAiSizingVendor::class)]
#[UsesClass(SizingAgent::class)]
#[UsesClass(SizingPrompt::class)]
#[UsesClass(SizingVerdict::class)]
#[UsesClass(SizingRequest::class)]
#[UsesClass(SizingOutcome::class)]
#[UsesClass(SizeTier::class)]
#[UsesClass(ReadinessGap::class)]
#[UsesClass(BuildQuestion::class)]
#[UsesClass(BuildRun::class)]
#[UsesClass(ParkedTasks::class)]
#[UsesClass(Fence::class)]
#[UsesClass(UntrustedSource::class)]
#[UsesClass(Tier::class)]
#[UsesClass(Provider::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(TierRoutedPlatform::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(UsageStarted::class)]
#[UsesClass(UsageReservation::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(EligibleTasks::class)]
#[UsesClass(SizingRecords::class)]
#[UsesClass(EligibilityLabels::class)]
#[UsesClass(ProjectEligibilityLabel::class)]
#[UsesClass(ListedIssue::class)]
#[UsesClass(IssueHistory::class)]
#[UsesClass(IssueLabels::class)]
#[UsesClass(LabelApplication::class)]
final class SizingASampleTaskTest extends KernelTestCase
{
    public function testASampleTaskIsSizedAndItsVerdictAndTokensAreRecordedAgainstIt(): void
    {
        $ledger = new InMemoryLedger();

        $outcome = $this->sizing('{"ready":true,"missing":[],"size":"medium","estimated_lines":180,"reason":"One change.","parts":[]}', new SizingFixture(self::createStub(ManagerRegistry::class), $ledger, 12))->run('sample', 12);

        self::assertSame(SizingOutcome::Ready, $outcome);
        self::assertSame(
            [['usage_recorded', 'started', '12'], ['usage_recorded', 'settled', null], ['task_sized', null, '12']],
            array_map(static fn (LedgerEventKind $kind, array $payload): array => [$kind->value, $payload['phase'] ?? null, $payload['task'] ?? null], $ledger->kinds(), $ledger->payloads()),
        );
        self::assertSame(['outcome' => 'ready', 'missing' => [], 'size' => 'medium', 'estimated_lines' => 180], array_intersect_key($ledger->payloads()[2], array_flip(['outcome', 'missing', 'size', 'estimated_lines'])));
    }

    public function testASampleTaskWithoutAnAcceptanceConditionIsParkedAndItsVerdictRecorded(): void
    {
        $ledger = new InMemoryLedger();
        $fixture = new SizingFixture(self::createStub(ManagerRegistry::class), $ledger, 12);

        $outcome = $this->sizing('{"ready":false,"missing":["acceptance_condition"],"size":"small","estimated_lines":25,"reason":"No done condition.","parts":[]}', $fixture)->run('sample', 12);

        self::assertSame(SizingOutcome::Parked, $outcome);
        self::assertSame(['sample:12'], $fixture->parked->fetchFirstColumn("SELECT project || ':' || task FROM build_parked_task"));
        self::assertSame([['sample', 12, "This task is not ready to build. It lacks: an acceptance condition.\n\n- Add the missing items to the task\n- Close the task"]], $fixture->comments->posted);
        $record = array_last($ledger->payloads());
        self::assertSame(['parked', ['acceptance_condition']], [$record['outcome'] ?? null, $record['missing'] ?? null]);
    }

    private function sizing(string $answer, SizingFixture $fixture): SizeTask
    {
        $resolver = new ConfiguredTierResolver(['sample' => ['tiers' => ['standard' => ['provider' => 'anthropic', 'model' => 'middle']]]], []);
        $platform = MockPlatformFactory::createPlatform(static fn (): string => $answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $usage = new UsageLedger($fixture->ledger, new MockClock('2026-11-28 12:00:00 UTC'), new NullLogger(), StoredSerializerFactory::create());
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return $fixture->sizing(new SymfonyAiSizingVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), new SizingAgent(new SizingPrompt(new Fence())), $usage, $limit));
    }
}
