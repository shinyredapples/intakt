<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(AccountLimitedVendor::class)]
#[UsesClass(ReviewRequest::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(ReviewerConversation::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(VendorAccountBusy::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Verdict::class)]
final class AccountLimitedVendorTest extends TestCase
{
    public function testAReviewOnAnAccountWithAFreePlaceReturnsTheVendorsVerdict(): void
    {
        $verdict = Verdict::unreadApproval();
        $vendor = $this->createStub(Vendor::class);
        $vendor->method('review')->willReturn($verdict);

        $result = new AccountLimitedVendor($vendor, $this->resolver(), $this->limit())->review('intakt', Tier::Standard, $this->request());

        self::assertSame($verdict, $result);
    }

    public function testAReviewOnAFullAccountIsRefusedWithoutCallingTheVendor(): void
    {
        $limit = $this->limit();
        $called = false;
        $vendor = $this->createStub(Vendor::class);
        $vendor->method('review')->willReturnCallback(static function () use (&$called): Verdict {
            $called = true;

            return Verdict::unreadApproval();
        });

        $this->expectException(VendorAccountBusy::class);

        try {
            $limit->run(Provider::Anthropic, fn (): Verdict => new AccountLimitedVendor($vendor, $this->resolver(), $limit)->review('intakt', Tier::Standard, $this->request()));
        } finally {
            self::assertFalse($called);
        }
    }

    private function limit(): VendorAccountLimit
    {
        return new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);
    }

    private function resolver(): TierResolver
    {
        $resolver = $this->createStub(TierResolver::class);
        $resolver->method('resolve')->willReturn(new ResolvedModel(Provider::Anthropic, 'claude-test'));

        return $resolver;
    }

    private function request(): ReviewRequest
    {
        return new ReviewRequest(new Checkout(sys_get_temp_dir()), 'Review the change.', new ReviewerConversation('You review for correctness.', new UuidV7()));
    }
}
