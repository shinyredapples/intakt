<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use ArrayObject;
use Closure;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\KeyedPlatforms;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;

final readonly class ScriptedKeyedPlatforms implements KeyedPlatforms
{
    /**
     * @param ArrayObject<int, array{string, array<string, mixed>}> $calls
     * @param Closure(): (ResultInterface|string)                   $answer
     */
    public function __construct(
        private ArrayObject $calls,
        private Closure $answer,
    ) {
    }

    public function platformFor(ProviderKey $key): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(function (Model $model, array|string|object $input, array $options): ResultInterface|string {
            /* @var array<string, mixed> $options */
            $this->calls->append([$model->getName(), $options]);

            return ($this->answer)();
        });
    }
}
