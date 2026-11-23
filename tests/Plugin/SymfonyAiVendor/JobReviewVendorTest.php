<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\JobReview;
use Intakt\Core\Review\Contract\JobReviewer;
use Intakt\Core\Review\Contract\JobVerdict;
use Intakt\Core\Review\Contract\Judge;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageUnsettled;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnsweringTier;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\JobReportedNoUsage;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\UuidV7;

// The job reviewer is a stand-in, because what is under test is how the vendor counts a job's approve and settles its usage, not the job itself.
#[CoversClass(SymfonyAiVendor::class)]
#[CoversClass(UsageLedger::class)]
#[CoversClass(JobReportedNoUsage::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(AnsweringTier::class)]
#[UsesClass(UsageReservation::class)]
#[UsesClass(UsageStarted::class)]
#[UsesClass(UsageSettled::class)]
#[UsesClass(UsageUnsettled::class)]
#[UsesClass(TokenUsageRow::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(JobReview::class)]
#[UsesClass(JobVerdict::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(CommitId::class)]
final class JobReviewVendorTest extends TestCase
{
    private InMemoryLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLedger();
    }

    public function testAJobApproveWithoutAReadOfTheCheckoutIsBlockedAsAnUnreadApproval(): void
    {
        $verdict = $this->reviewed(new JobVerdict($this->approve(), false, [$this->spent()]));

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertEquals(Verdict::unreadApproval(), $verdict);
    }

    public function testAJobApproveAfterAReadOfTheCheckoutCounts(): void
    {
        $verdict = $this->reviewed(new JobVerdict($this->approve(), true, [$this->spent()]));

        self::assertSame(Decision::Approve, $verdict->verdict);
    }

    public function testAJobsReportedUsageSettlesTheCall(): void
    {
        $spent = new TokenUsage(
            promptTokens: 1200,
            completionTokens: 80,
            cachedTokens: 900,
        );

        $this->reviewed(new JobVerdict($this->approve(), true, [$spent]));

        $settled = $this->ledger->payloads()[1];
        self::assertSame('settled', $settled['phase']);
        $usages = $settled['usages'];
        self::assertIsArray($usages);
        $row = $usages[0];
        self::assertIsArray($row);
        self::assertSame([1200, 80, 900], [$row['prompt'], $row['completion'], $row['cached']]);
    }

    public function testAJobThatReportedNoUsageLeavesTheCallUnsettledInsteadOfSettlingZero(): void
    {
        $this->reviewed(new JobVerdict($this->approve(), true, []));

        $unsettled = $this->ledger->payloads()[1];
        self::assertSame(['unsettled', JobReportedNoUsage::class, []], [$unsettled['phase'], $unsettled['failure'], $unsettled['usages']]);
    }

    private function approve(): Verdict
    {
        return new Verdict(Decision::Approve, [], [], []);
    }

    private function spent(): TokenUsage
    {
        return new TokenUsage(
            promptTokens: 10,
            completionTokens: 2,
        );
    }

    private function reviewed(JobVerdict $answer): Verdict
    {
        $answering = new ResolvedModel(Provider::ClaudeCode, 'review-model');
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('answering')->willReturn($answering);
        $jobs = self::createStub(JobReviewer::class);
        $jobs->method('review')->willReturn($answer);
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'claude_code', 'model' => 'review-model']]]], []);
        $clock = new MockClock('2026-11-19 10:00:00');
        $usage = new UsageLedger($this->ledger, $clock, new NullLogger(), StoredSerializerFactory::create());
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);
        $vendor = new SymfonyAiVendor($tiers, $resolver, self::createStub(Reviewer::class), self::createStub(Judge::class), $usage, $limit, $clock, self::createStub(ReviewServerAccesses::class), $jobs);
        $request = new ReviewRequest(
            new Checkout(sys_get_temp_dir()),
            new ReviewBrief(new ChangeUnderReview('alpha', 'Task', 'Diff'), [], 1),
            new ReviewerConversation('Review correctness', new UuidV7()),
            new JobReview(new CommitId(str_repeat('a', 40)), 'correctness'),
        );

        return $vendor->review('alpha', Tier::Strong, $request)->verdict;
    }
}
