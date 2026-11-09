<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\ReviewServerAnswers;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UnstreamedModelCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;

#[CoversClass(TierRoutedPlatform::class)]
#[CoversClass(UnstreamedModelCatalog::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierRequest::class)]
final class TierRoutedPlatformTest extends TestCase
{
    public function testAnApiProviderModelCanStream(): void
    {
        $platform = new TierRoutedPlatform(self::createStub(TierInvoker::class), new ResolvedModel(Provider::Anthropic, 'big'));

        self::assertTrue($platform->getModelCatalog()->getModel('big')->supports(Capability::OUTPUT_STREAMING));
    }

    public function testASubscriptionCliModelCannotStreamButKeepsItsOtherCapabilities(): void
    {
        $platform = new TierRoutedPlatform(self::createStub(TierInvoker::class), new ResolvedModel(Provider::ClaudeCode, 'big'));

        $model = $platform->getModelCatalog()->getModel('big');

        self::assertFalse($model->supports(Capability::OUTPUT_STREAMING));
        self::assertTrue($model->supports(Capability::OUTPUT_STRUCTURED));
    }

    public function testForgettingTheReviewCredentialReachesEveryRoundThatCalledTheReviewServer(): void
    {
        $call = $this->createMockForIntersectionOfInterfaces([RawResultInterface::class, ReviewServerAnswers::class]);
        $call->expects(self::exactly(2))->method('forgetTheCredential');
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('invoke')->willReturnCallback(static fn (): DeferredResult => new DeferredResult(self::createStub(ResultConverterInterface::class), $call));
        $platform = new TierRoutedPlatform($tiers, new ResolvedModel(Provider::ClaudeCode, 'big'));
        $platform->invoke('big', 'First round.');
        $platform->invoke('big', 'Second round.');

        $platform->forgetTheReviewCredential();
    }

    public function testARoundWithoutTheReviewServerHasNoCredentialToForget(): void
    {
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('invoke')->willReturn(new DeferredResult(self::createStub(ResultConverterInterface::class), self::createStub(RawResultInterface::class)));
        $platform = new TierRoutedPlatform($tiers, new ResolvedModel(Provider::Anthropic, 'big'));
        $platform->invoke('big', 'Review.');

        $platform->forgetTheReviewCredential();

        self::assertSame([], $platform->reviewServerAnswers());
    }
}
