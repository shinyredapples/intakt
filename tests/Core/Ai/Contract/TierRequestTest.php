<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Contract;

use ArrayObject;
use Intakt\Core\Ai\Contract\TierPart;
use Intakt\Core\Ai\Contract\TierRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

#[CoversClass(TierRequest::class)]
#[CoversClass(TierPart::class)]
final class TierRequestTest extends TestCase
{
    public function testAnOptionTheCallerSetIsKeptOverADefaultAddedLater(): void
    {
        $sent = $this->whenSent(TierRequest::of('review')->withDefaults(temperature: 0.1)->withDefaults(
            temperature: 0.9,
            max_output_tokens: 10,
        ));

        self::assertSame(['temperature' => 0.1, 'max_output_tokens' => 10], $sent['options']);
    }

    public function testPartsWithSparseIntegerKeysReachThePlatformWithTheirKeysUnchanged(): void
    {
        $sent = $this->whenSent(TierRequest::ofParts(new TierPart(7, 'context'), new TierPart(2, 'first')));

        self::assertSame([7 => 'context', 2 => 'first'], $sent['input']);
    }

    public function testAStringKeyBeforeAnIntegerKeyKeepsItsOrder(): void
    {
        $sent = $this->whenSent(TierRequest::ofParts(new TierPart('prompt', 'review'), new TierPart(4, 'context'), new TierPart(0, 'first')));

        self::assertSame(['prompt' => 'review', 4 => 'context', 0 => 'first'], $sent['input']);
    }

    public function testNoPartsGiveAnEmptyArrayInput(): void
    {
        $sent = $this->whenSent(TierRequest::ofParts());

        self::assertSame([], $sent['input']);
    }

    public function testATextInputReachesThePlatformWithoutOptions(): void
    {
        $sent = $this->whenSent(TierRequest::of('review'));

        self::assertSame(['input' => 'review', 'options' => []], [...$sent]);
    }

    /**
     * @return ArrayObject<string, mixed>
     */
    private function whenSent(TierRequest $request): ArrayObject
    {
        /** @var ArrayObject<string, mixed> $sent */
        $sent = new ArrayObject();
        $inner = MockPlatformFactory::createPlatform('ok');
        $platform = self::createStub(PlatformInterface::class);
        $platform->method('invoke')->willReturnCallback(static function (Model|string $model, array|string|object $input, array $options) use ($sent, $inner): DeferredResult {
            $sent['input'] = $input;
            $sent['options'] = $options;

            return $inner->invoke('model', 'review');
        });

        $request->invokeOn($platform, 'model')->asText();

        return $sent;
    }
}
