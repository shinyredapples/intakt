<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UnstreamedModelCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Capability;

#[CoversClass(TierRoutedPlatform::class)]
#[CoversClass(UnstreamedModelCatalog::class)]
#[UsesClass(ResolvedModel::class)]
final class TierRoutedPlatformTest extends TestCase
{
    public function testAnApiProviderModelCanStream(): void
    {
        $platform = new TierRoutedPlatform($this->createStub(TierInvoker::class), new ResolvedModel(Provider::Anthropic, 'big'));

        self::assertTrue($platform->getModelCatalog()->getModel('big')->supports(Capability::OUTPUT_STREAMING));
    }

    public function testASubscriptionCliModelCannotStreamButKeepsItsOtherCapabilities(): void
    {
        $platform = new TierRoutedPlatform($this->createStub(TierInvoker::class), new ResolvedModel(Provider::ClaudeCode, 'big'));

        $model = $platform->getModelCatalog()->getModel('big');

        self::assertFalse($model->supports(Capability::OUTPUT_STREAMING));
        self::assertTrue($model->supports(Capability::OUTPUT_STRUCTURED));
    }
}
