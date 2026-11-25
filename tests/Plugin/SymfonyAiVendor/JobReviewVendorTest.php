<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
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
use Intakt\Core\Review\Contract\VerdictUnreadable;
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
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
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
#[UsesClass(Account::class)]
#[UsesClass(VerdictUnreadable::class)]
final class JobReviewVendorTest extends TestCase
{
    private InMemoryLedger $ledger;

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

    public function testAnUnreadableVerdictWithReportedUsageSettlesTheCallWithIt(): void
    {
        $spent = new TokenUsage(
            promptTokens: 100,
            completionTokens: 20,
        );

        try {
            $this->reviewed(new JobVerdict(VerdictUnreadable::because('no verdict'), false, [$spent], new Account(Provider::ClaudeCode, 'team')));
            self::fail('An answer that is no verdict was taken as one.');
        } catch (VerdictUnreadable) {
        }

        $settled = $this->ledger->payloads()[1];
        self::assertSame('settled', $settled['phase']);
        $usages = $settled['usages'];
        self::assertIsArray($usages);
        $row = $usages[0];
        self::assertIsArray($row);
        self::assertSame(['claude_code.team', 100, 20], [$row['account'], $row['prompt'], $row['completion']]);
    }

    public function testAFailedJobWithReportedUsageSettlesTheCallWithItAndFails(): void
    {
        $failure = new RuntimeException('The CLI failed.');

        try {
            $this->reviewed(new JobVerdict($failure, false, [$this->spent()]));
            self::fail('A failed job was taken as a verdict.');
        } catch (RuntimeException $thrown) {
            self::assertSame($failure, $thrown);
        }

        self::assertSame('settled', $this->ledger->payloads()[1]['phase']);
    }

    public function testAJobThatThrowsWithoutUsageLeavesTheCallUnsettledAndFailsTyped(): void
    {
        $jobs = self::createStub(JobReviewer::class);
        $jobs->method('review')->willThrowException(new EveryAccountCoolingDown(Provider::ClaudeCode, 60));

        try {
            $this->reviewedOn(Provider::ClaudeCode, $jobs, self::createStub(Reviewer::class));
            self::fail('A job that threw was taken as a verdict.');
        } catch (ProviderRateLimited) {
        }

        self::assertSame(['unsettled', EveryAccountCoolingDown::class], [$this->ledger->payloads()[1]['phase'], $this->ledger->payloads()[1]['failure']]);
    }

    /**
     * @return iterable<string, array{Provider}>
     */
    public static function providersWithoutAJob(): iterable
    {
        yield 'an API key' => [Provider::Anthropic];
        yield 'Codex' => [Provider::Codex];
    }

    #[DataProvider('providersWithoutAJob')]
    public function testAnotherProviderReviewsInTheAppsOwnProcess(Provider $provider): void
    {
        $jobs = $this->createMock(JobReviewer::class);
        $jobs->expects(self::never())->method('review');
        $reviewer = $this->createMock(Reviewer::class);
        $reviewer->expects(self::once())->method('review')->willReturn(Verdict::unreadApproval());

        $this->reviewedOn($provider, $jobs, $reviewer);
    }

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLedger();
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
        $jobs = self::createStub(JobReviewer::class);
        $jobs->method('review')->willReturn($answer);

        return $this->reviewedOn(Provider::ClaudeCode, $jobs, self::createStub(Reviewer::class));
    }

    private function reviewedOn(Provider $provider, JobReviewer $jobs, Reviewer $reviewer): Verdict
    {
        $answering = new ResolvedModel($provider, 'review-model');
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('answering')->willReturn($answering);
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => $provider->value, 'model' => 'review-model']]]], []);
        $clock = new MockClock('2026-11-19 10:00:00');
        $usage = new UsageLedger($this->ledger, $clock, new NullLogger(), StoredSerializerFactory::create());
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);
        $vendor = new SymfonyAiVendor($tiers, $resolver, $reviewer, self::createStub(Judge::class), $usage, $limit, $clock, self::createStub(ReviewServerAccesses::class), $jobs);
        $request = new ReviewRequest(
            new Checkout(sys_get_temp_dir()),
            new ReviewBrief(new ChangeUnderReview('alpha', 'Task', 'Diff'), [], 1),
            new ReviewerConversation('Review correctness', new UuidV7()),
            new JobReview(new CommitId(str_repeat('a', 40)), 'correctness'),
        );

        return $vendor->review('alpha', Tier::Strong, $request)->verdict;
    }
}
