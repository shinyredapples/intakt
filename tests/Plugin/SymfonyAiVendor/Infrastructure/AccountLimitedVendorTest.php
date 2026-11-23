<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\KeyRejected;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierProviderDisabled;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;
use Throwable;

#[CoversClass(AccountLimitedVendor::class)]
#[CoversClass(VendorAccountOut::class)]
#[UsesClass(ReviewRequest::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(ReviewerConversation::class)]
#[UsesClass(ReviewBrief::class)]
#[UsesClass(ChangeUnderReview::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(JudgeRequest::class)]
#[UsesClass(Paths::class)]
#[UsesClass(ProviderRateLimited::class)]
#[UsesClass(ProviderFailedTransiently::class)]
#[UsesClass(ProviderRejectedTheKey::class)]
#[UsesClass(TierProviderDisabled::class)]
#[UsesClass(TierResolutionFailure::class)]
#[UsesClass(Account::class)]
#[UsesClass(KeyRejected::class)]
#[UsesClass(EveryKeyRejected::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
final class AccountLimitedVendorTest extends TestCase
{
    public function testAReviewOnAnAccountWithAFreePlaceReturnsTheVendorsVerdict(): void
    {
        $verdict = new VendorReview(Verdict::unreadApproval());
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

        $result = $this->vendor($vendor)->judge('intakt', new JudgeRequest('Add a cart.', [], new UuidV7(), new Paths()));

        self::assertSame($judgement, $result);
    }

    public function testWhetherTheProjectMapsAModelToATierIsTheWrappedVendorsAnswerForThatTier(): void
    {
        $vendor = self::createStub(Vendor::class);
        $vendor->method('mapsAModelTo')->willReturnMap([['intakt', Tier::Strong, true], ['intakt', Tier::Light, false]]);

        $limited = $this->vendor($vendor);

        self::assertSame([true, false], [$limited->mapsAModelTo('intakt', Tier::Strong), $limited->mapsAModelTo('intakt', Tier::Light)]);
    }

    public function testARateLimitWhileAnotherAccountOfTheVendorIsFreeMovesTheReviewThereAtOnce(): void
    {
        $limited = new ProviderRateLimited(300, new RuntimeException('429'));

        $out = $this->failureOf($this->vendor($this->failing($limited), $this->cooldowns(0)));

        self::assertInstanceOf(VendorAccountOut::class, $out);
        self::assertSame($limited, $out->getPrevious());
    }

    public function testARateLimitedJudgementWhileAnotherAccountOfTheVendorIsFreeMovesThereAtOnce(): void
    {
        $vendor = self::createStub(Vendor::class);
        $vendor->method('judge')->willThrowException(new ProviderRateLimited(300, new RuntimeException('429')));

        $this->expectException(VendorAccountOut::class);

        $this->vendor($vendor, $this->cooldowns(0))->judge('intakt', new JudgeRequest('Add a cart.', [], new UuidV7(), new Paths()));
    }

    public function testARateLimitWhileEveryAccountOfTheVendorCoolsDownWaitsUntilTheFirstIsFree(): void
    {
        $out = $this->failureOf($this->vendor($this->failing(new ProviderRateLimited(300, new RuntimeException('429'))), $this->cooldowns(45)));

        self::assertInstanceOf(ProviderRateLimited::class, $out);
        self::assertSame(45, $out->retryAfterSeconds);
    }

    public function testARateLimitCarryingTheFailoversWaitWaitsThatLongEvenWhenTheAccountCooldownsReadZero(): void
    {
        $limited = new ProviderRateLimited(300, new EveryAccountCoolingDown(Provider::OpenAiCompatible, 42));

        $out = $this->failureOf($this->vendor($this->failing($limited), $this->cooldowns(0)));

        self::assertInstanceOf(ProviderRateLimited::class, $out);
        self::assertSame(42, $out->retryAfterSeconds);
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

    public function testALimitWhileEveryAccountIsOutStartsAgainAtOnceWhenTheTierHasSubstitutes(): void
    {
        $limited = new ProviderRateLimited(300, new RuntimeException('429'));

        $out = $this->failureOf($this->vendor($this->failing($limited), $this->cooldowns(45), $this->tierWithSubstitutes()));

        self::assertInstanceOf(VendorAccountOut::class, $out);
    }

    public function testARejectedKeyWithNoOtherAccountOrSubstituteIsTheReviewersFailure(): void
    {
        $rejected = new ProviderRejectedTheKey(new RuntimeException('401'));

        self::assertSame($rejected, $this->failureOf($this->vendor($this->failing($rejected), $this->cooldowns(45))));
    }

    public function testARejectedKeyWhileAnotherAccountIsFreeMovesTheReviewThereAtOnce(): void
    {
        $rejected = new ProviderRejectedTheKey(new KeyRejected(new Account(Provider::Anthropic, 'first')));

        $out = $this->failureOf($this->vendor($this->failing($rejected), $this->cooldowns(0)));

        self::assertInstanceOf(VendorAccountOut::class, $out);
    }

    public function testARejectedKeyOnAProviderWithoutAnAccountsListEndsTheReviewer(): void
    {
        $rejected = new ProviderRejectedTheKey(new RuntimeException('401'));

        self::assertSame($rejected, $this->failureOf($this->vendor($this->failing($rejected), $this->cooldowns(0))));
    }

    public function testARejectedKeyOnEveryAccountEndsTheReviewerEvenWhileTheCooldownsReportAFreeOne(): void
    {
        $rejected = new ProviderRejectedTheKey(new EveryKeyRejected(Provider::Anthropic));

        self::assertSame($rejected, $this->failureOf($this->vendor($this->failing($rejected), $this->cooldowns(0))));
    }

    public function testARejectedKeyWithASubstituteStartsAgainAtOnce(): void
    {
        $rejected = new ProviderRejectedTheKey(new RuntimeException('401'));

        $out = $this->failureOf($this->vendor($this->failing($rejected), $this->cooldowns(45), $this->tierWithSubstitutes()));

        self::assertInstanceOf(VendorAccountOut::class, $out);
    }

    public function testAFailureOfASubstituteWhileTheTiersOwnProviderIsDisabledStartsAgainAtOnce(): void
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('resolve')->willThrowException(new TierProviderDisabled('intakt', Tier::Standard, 'disabled'));
        $resolver->method('hasSubstitutes')->willReturn(true);

        $limited = $this->failureOf($this->vendor($this->failing(new ProviderRateLimited(300, new RuntimeException('429'))), $this->cooldowns(45), $resolver));
        $rejected = $this->failureOf($this->vendor($this->failing(new ProviderRejectedTheKey(new RuntimeException('401'))), $this->cooldowns(45), $resolver));

        self::assertInstanceOf(VendorAccountOut::class, $limited);
        self::assertInstanceOf(VendorAccountOut::class, $rejected);
    }

    private function failing(Throwable $failure): Vendor
    {
        $vendor = self::createStub(Vendor::class);
        $vendor->method('review')->willThrowException($failure);

        return $vendor;
    }

    private function vendor(Vendor $vendor, ?AccountCooldowns $cooldowns = null, ?TierResolver $resolver = null): AccountLimitedVendor
    {
        return new AccountLimitedVendor($vendor, $resolver ?? $this->tier(), $cooldowns ?? $this->cooldowns(0));
    }

    private function cooldowns(int $secondsUntilAnAccountIsFree): AccountCooldowns
    {
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilAnAccountIsFree')->willReturn($secondsUntilAnAccountIsFree);

        return $cooldowns;
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

    private function tier(): TierResolver
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('resolve')->willReturn(new ResolvedModel(Provider::Anthropic, 'claude-test'));

        return $resolver;
    }

    private function tierWithSubstitutes(): TierResolver
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('resolve')->willReturn(new ResolvedModel(Provider::Anthropic, 'claude-test'));
        $resolver->method('hasSubstitutes')->willReturn(true);

        return $resolver;
    }

    private function request(): ReviewRequest
    {
        return new ReviewRequest(new Checkout(sys_get_temp_dir()), new ReviewBrief(new ChangeUnderReview('alpha', 'Review the change.', 'diff --git a/a b/a'), [], 1), new ReviewerConversation('You review for correctness.', new UuidV7()));
    }
}
