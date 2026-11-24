<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use ArrayObject;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierPart;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UnstreamedModelCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

#[CoversClass(TierRoutedPlatform::class)]
#[CoversClass(UnstreamedModelCatalog::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierRequest::class)]
#[UsesClass(TierPart::class)]
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

    public function testPerCallOptionsWinOverThePlatformsOwnOptions(): void
    {
        $answer = MockPlatformFactory::createPlatform('ok');
        /** @var ArrayObject<string, mixed> $received */
        $received = new ArrayObject();
        $tiers = $this->createMock(TierInvoker::class);
        $tiers->expects(self::once())->method('invoke')->willReturnCallback(function (ResolvedModel $model, TierRequest $request) use ($received, $answer): DeferredResult {
            $received['options'] = $this->optionsSentBy($request);

            return $answer->invoke('model', 'review');
        });
        $platform = new TierRoutedPlatform($tiers, new ResolvedModel(Provider::Anthropic, 'big'), ['x' => 'platform', 'y' => 'platform']);

        $platform->invoke('big', 'review', ['x' => 'call']);

        self::assertSame(['x' => 'call', 'y' => 'platform'], $received['options']);
    }

    public function testAnArrayInputKeepsItsOriginalKeysAndOrder(): void
    {
        $this->assertInputReachesThePlatformUnchanged(['prompt' => 'review', 4 => 'context', 1 => 'first']);
    }

    public function testAnEmptyArrayInputReachesThePlatformAsAnEmptyArray(): void
    {
        $this->assertInputReachesThePlatformUnchanged([]);
    }

    /**
     * @param array<mixed> $input
     */
    private function assertInputReachesThePlatformUnchanged(array $input): void
    {
        $result = MockPlatformFactory::createPlatform('ok')->invoke('model', 'review');
        $inner = $this->createMock(PlatformInterface::class);
        $inner->expects(self::once())->method('invoke')->with('big', $input, [])->willReturn($result);
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('invoke')->willReturnCallback(
            static fn (ResolvedModel $model, TierRequest $request): DeferredResult => $request->invokeOn($inner, 'big'),
        );
        $platform = new TierRoutedPlatform($tiers, new ResolvedModel(Provider::Anthropic, 'big'));

        $platform->invoke('big', $input);
    }

    private function optionsSentBy(TierRequest $request): mixed
    {
        /** @var ArrayObject<string, mixed> $sent */
        $sent = new ArrayObject();
        $answer = MockPlatformFactory::createPlatform('ok');
        $inner = self::createStub(PlatformInterface::class);
        $inner->method('invoke')->willReturnCallback(static function (Model|string $model, array|string|object $input, array $options) use ($sent, $answer): DeferredResult {
            $sent['options'] = $options;

            return $answer->invoke('model', 'review');
        });

        $request->invokeOn($inner, 'big')->asText();

        return $sent['options'];
    }
}
