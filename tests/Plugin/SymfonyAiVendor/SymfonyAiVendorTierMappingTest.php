<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ai\Contract\ProjectNotConfigured;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Review\Contract\JobReviewer;
use Intakt\Core\Review\Contract\Judge;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

#[CoversClass(SymfonyAiVendor::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(TierNotMapped::class)]
#[UsesClass(ProjectNotConfigured::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(VendorAccountLimit::class)]
final class SymfonyAiVendorTierMappingTest extends TestCase
{
    public function testAProjectThatMapsAModelToTheTierSaysSo(): void
    {
        self::assertTrue($this->vendor()->mapsAModelTo('alpha', Tier::Strong));
    }

    public function testAProjectWithoutALightTierMapsNoModelToIt(): void
    {
        self::assertFalse($this->vendor()->mapsAModelTo('alpha', Tier::Light));
    }

    public function testATierWhoseProviderIsDisabledStillCountsAsMapped(): void
    {
        self::assertTrue($this->vendor(['anthropic' => 'paused by the owner'])->mapsAModelTo('alpha', Tier::Strong));
    }

    public function testAProjectThatIsNotConfiguredIsNoAnswerButItsOwnFailure(): void
    {
        $this->expectException(ProjectNotConfigured::class);

        $this->vendor()->mapsAModelTo('unknown', Tier::Strong);
    }

    /**
     * @param array<string, string> $disabledProviders
     */
    private function vendor(array $disabledProviders = []): SymfonyAiVendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'large']]]], $disabledProviders);
        $clock = new MockClock('2026-11-13 10:00:00');
        $usage = new UsageLedger(new InMemoryLedger(), $clock, new NullLogger(), StoredSerializerFactory::create());
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiVendor(self::createStub(TierInvoker::class), $resolver, self::createStub(Reviewer::class), self::createStub(Judge::class), $usage, $limit, $clock, self::createStub(ReviewServerAccesses::class), self::createStub(JobReviewer::class));
    }
}
