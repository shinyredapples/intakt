<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Contract\TierResolver;
use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Lets an agent call a project's tier: the agent's model name is the tier, and every round is kept so the vendor can settle the usage of all of them; excluded from the container because one is built per review (SymfonyAiVendor creates it with new), so its rounds never outlive the review.
#[Exclude]
final class TierRoutedPlatform implements PlatformInterface
{
    /**
     * @var list<DeferredResult>
     */
    private array $rounds = [];

    public function __construct(
        private readonly TierInvoker $tiers,
        private readonly TierResolver $resolver,
        private readonly string $project,
    ) {
    }

    /**
     * @return list<DeferredResult>
     */
    public function rounds(): array
    {
        return $this->rounds;
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $name = $model instanceof Model ? $model->getName() : $model;

        // The tier's own model, never answering(), because the vendor still judges the tier's own provider (unread approvals, account limits) and a review is never continued by another vendor.
        $round = $this->tiers->invoke($this->resolver->resolve($this->project, Tier::from($name)), new TierRequest($input, $options));
        $this->rounds[] = $round;

        return $round;
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return new FallbackModelCatalog();
    }
}
