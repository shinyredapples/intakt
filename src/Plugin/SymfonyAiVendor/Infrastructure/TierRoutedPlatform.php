<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use ArrayObject;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

// Lets an agent call a project's tier: the agent's model name is the tier, and every round is kept so the vendor can settle the usage of all of them.
final class TierRoutedPlatform implements PlatformInterface
{
    /**
     * @var ArrayObject<int, DeferredResult>
     */
    private readonly ArrayObject $rounds;

    public function __construct(
        private readonly TierInvoker $tiers,
        private readonly string $project,
    ) {
        $this->rounds = new ArrayObject();
    }

    /**
     * @return list<DeferredResult>
     */
    public function rounds(): array
    {
        return $this->rounds->getArrayCopy();
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $name = $model instanceof Model ? $model->getName() : $model;

        $round = $this->tiers->invoke($this->project, Tier::from($name), new TierRequest($input, $options));
        $this->rounds[] = $round;

        return $round;
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return new FallbackModelCatalog();
    }
}
