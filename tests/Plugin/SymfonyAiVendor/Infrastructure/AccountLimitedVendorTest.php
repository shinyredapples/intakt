<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\UuidV7;
use Throwable;

#[CoversClass(AccountLimitedVendor::class)]
#[CoversClass(VendorAccountOut::class)]
#[UsesClass(ReviewRequest::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(ReviewerConversation::class)]
#[UsesClass(ReviewBrief::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(VendorAccountBusy::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(JudgeRequest::class)]
#[UsesClass(ProviderRateLimited::class)]
#[UsesClass(ProviderFailedTransiently::class)]
final class AccountLimitedVendorTest extends TestCase
{
    public function testAReviewOnAnAccountWithAFreePlaceReturnsTheVendorsVerdict(): void
    {
        $verdict = Verdict::unreadApproval();
        $vendor = self::createStub(Vendor::class);
        $vendor->method('review')->willReturn($verdict);

        $result = $this->vendor($vendor)->review('intakt', Tier::Standard, $this->request());

        self::assertSame($verdict, $result);
    }

    public function testAJudgementOnAnAccountWithAFreePlaceReturnsTheVendorsJudgement(): void
    {
        $judgement = new Judgement([]);
        $vendor = self::createStub(Vendor::class);
        $vendor->method('judge')->willReturn($judgement);

        $result = $this->vendor($vendor)->judge('intakt', new JudgeRequest('Add a cart.', []));

        self::assertSame($judgement, $result);
    }

    public function testAReviewOnAFullAccountIsRefusedWithoutCallingTheVendor(): void
    {
        $limit = $this->limit();
        $called = false;
        $vendor = self::createStub(Vendor::class);
        $vendor->method('review')->willReturnCallback(static function () use (&$called): Verdict {
            $called = true;

            return Verdict::unreadApproval();
        });

        $this->expectException(VendorAccountBusy::class);

        try {
            $limit->run(Provider::Anthropic, fn (): Verdict => new AccountLimitedVendor($vendor, $this->resolver(), $limit, $this->cooldowns(0))->review('intakt', Tier::Standard, $this->request()));
        } finally {
            self::assertFalse($called);
        }
    }

    public function testARateLimitWhileAnotherAccountOfTheVendorIsFreeMovesTheReviewThereAtOnce(): void
    {
        $limited = new ProviderRateLimited(300, new RuntimeException('429'));

        $out = $this->failureOf($this->vendor($this->failing($limited), $this->cooldowns(0)));

        self::assertInstanceOf(VendorAccountOut::class, $out);
        self::assertSame($limited, $out->getPrevious());
    }

    public function testARateLimitWhileEveryAccountOfTheVendorCoolsDownWaitsUntilTheFirstIsFree(): void
    {
        $out = $this->failureOf($this->vendor($this->failing(new ProviderRateLimited(300, new RuntimeException('429'))), $this->cooldowns(45)));

        self::assertInstanceOf(ProviderRateLimited::class, $out);
        self::assertSame(45, $out->retryAfterSeconds);
    }

    public function testATransientFailureWhileAnotherAccountOfTheVendorIsFreeMovesTheReviewThereAtOnce(): void
    {
        $out = $this->failureOf($this->vendor($this->failing(new ProviderFailedTransiently(new RuntimeException('503'))), $this->cooldowns(0)));

        self::assertInstanceOf(VendorAccountOut::class, $out);
    }

    public function testATransientFailureWhileEveryAccountOfTheVendorCoolsDownWaitsUntilTheFirstIsFree(): void
    {
        $failed = new ProviderFailedTransiently(new RuntimeException('503'));

        $out = $this->failureOf($this->vendor($this->failing($failed), $this->cooldowns(45)));

        self::assertInstanceOf(ProviderRateLimited::class, $out);
        self::assertSame(45, $out->retryAfterSeconds);
        self::assertSame($failed, $out->getPrevious());
    }

    public function testAnotherFailureIsNeverAMoveToAnotherAccount(): void
    {
        $failure = new RuntimeException('bad request');

        self::assertSame($failure, $this->failureOf($this->vendor($this->failing($failure), $this->cooldowns(0))));
    }

    private function failureOf(AccountLimitedVendor $vendor): Throwable
    {
        try {
            $_ = $vendor->review('intakt', Tier::Standard, $this->request());
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The review was expected to fail.');
    }

    private function failing(Throwable $failure): Vendor
    {
        $vendor = self::createStub(Vendor::class);
        $vendor->method('review')->willThrowException($failure);

        return $vendor;
    }

    private function vendor(Vendor $vendor, ?AccountCooldowns $cooldowns = null): AccountLimitedVendor
    {
        return new AccountLimitedVendor($vendor, $this->resolver(), $this->limit(), $cooldowns ?? $this->cooldowns(0));
    }

    private function cooldowns(int $secondsUntilAnAccountIsFree): AccountCooldowns
    {
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilAnAccountIsFree')->willReturn($secondsUntilAnAccountIsFree);

        return $cooldowns;
    }

    private function limit(): VendorAccountLimit
    {
        return new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);
    }

    private function resolver(): TierResolver
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('resolve')->willReturn(new ResolvedModel(Provider::Anthropic, 'claude-test'));

        return $resolver;
    }

    private function request(): ReviewRequest
    {
        return new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief('Review the change.', 'diff --git a/a b/a', []), new ReviewerConversation('You review for correctness.', new UuidV7()));
    }
}
