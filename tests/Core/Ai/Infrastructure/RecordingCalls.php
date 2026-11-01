<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

// Reports each call as it is sent, so a test sees which provider every round of a review went to, in order.
final readonly class RecordingCalls implements PlatformInterface
{
    /**
     * @param Closure(): void $onCall
     */
    public function __construct(
        private PlatformInterface $platform,
        private Closure $onCall,
    ) {
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        ($this->onCall)();

        return $this->platform->invoke($model, $input, $options);
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform->getModelCatalog();
    }
}
