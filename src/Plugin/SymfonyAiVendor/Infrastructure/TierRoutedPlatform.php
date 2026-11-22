<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Lets an agent call the one model chosen for its review: every round goes to it whatever model the agent names, so no other provider continues the conversation, and every round is kept so the vendor can settle the usage of all of them; excluded from the container because one is built per review (SymfonyAiVendor creates it with new), so its rounds never outlive the review.
#[Exclude]
final class TierRoutedPlatform implements PlatformInterface
{
    /**
     * @var list<DeferredResult>
     */
    private array $rounds = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly TierInvoker $tiers,
        public readonly ResolvedModel $answering,
        public readonly array $options = [],
        private readonly ?ModelCatalogInterface $catalog = null,
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
        $request = is_array($input) ? TierRequest::ofList(...$input) : TierRequest::of($input);
        $round = $this->tiers->invoke($this->answering, $request->withDefaults(...$options)->withDefaults(...$this->options));
        $this->rounds[] = $round;

        return $round;
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->catalog ?? ($this->answering->provider->streams() ? new FallbackModelCatalog() : new UnstreamedModelCatalog());
    }
}
