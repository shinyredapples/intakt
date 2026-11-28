<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
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
use Intakt\Core\Build\UseCase\SizeTask\SizeTask;
use Intakt\Core\Build\UseCase\SizeTask\SizingOutcome;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiSizingVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Build\ParkedTaskTable;
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
final class SizingASampleTaskTest extends KernelTestCase
{
    public function testASampleTaskIsSizedAndItsVerdictAndTokensAreRecordedAgainstIt(): void
    {
        $ledger = new InMemoryLedger();

        $outcome = $this->sizing('{"ready":true,"missing":[],"size":"medium","estimated_lines":180,"reason":"One change."}', $ledger, ParkedTaskTable::inMemory())->run('sample', 12);

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
        $parked = ParkedTaskTable::inMemory();

        $outcome = $this->sizing('{"ready":false,"missing":["acceptance_condition"],"size":"small","estimated_lines":25,"reason":"No done condition."}', $ledger, $parked)->run('sample', 12);

        self::assertSame(SizingOutcome::Parked, $outcome);
        self::assertSame(['sample:12'], $parked->fetchFirstColumn("SELECT project || ':' || task FROM build_parked_task"));
        self::assertSame(['parked', ['acceptance_condition']], [$ledger->payloads()[2]['outcome'], $ledger->payloads()[2]['missing']]);
    }

    private function sizing(string $answer, InMemoryLedger $ledger, Connection $parked): SizeTask
    {
        $resolver = new ConfiguredTierResolver(['sample' => ['tiers' => ['standard' => ['provider' => 'anthropic', 'model' => 'middle']]]], []);
        $platform = MockPlatformFactory::createPlatform(static fn (): string => $answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $usage = new UsageLedger($ledger, new MockClock('2026-11-28 12:00:00 UTC'), new NullLogger(), StoredSerializerFactory::create());
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);
        $vendor = new SymfonyAiSizingVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), new SizingAgent(new SizingPrompt(new Fence())), $usage, $limit);
        $reads = self::createStub(IssueReads::class);
        $reads->method('readOpen')->willReturn(new ListedIssue(12, 'Add a cart page', 'Users see their items on /cart.', new IssueLabels(), 'owner', new DateTimeImmutable('2026-11-20 10:00:00'), null, false));

        return new SizeTask($reads, $vendor, new ParkedTasks($parked, $ledger, self::createStub(ManagerRegistry::class), new MockClock('2026-11-28 12:00:00 UTC')), $ledger);
    }
}
