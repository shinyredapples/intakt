<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Ai\Contract\ReviewServerAnswers;
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

    public function __construct(
        private readonly TierInvoker $tiers,
        private readonly ResolvedModel $answering,
        private readonly ?ReviewServerAccess $server = null,
    ) {
    }

    /**
     * @return list<DeferredResult>
     */
    public function rounds(): array
    {
        return $this->rounds;
    }

    // From the provider and the access this platform carries, never from the answer, because a reviewer that could not read the change can still claim it did.
    public function readsTheCheckout(): bool
    {
        return $this->answering->provider->readsTheCheckoutThrough($this->server);
    }

    // Collected over every round, because a subscription CLI read these answers itself and only its stream holds them.
    /**
     * @return list<string>
     */
    public function reviewServerAnswers(): array
    {
        return array_merge([], ...array_map(static fn (ReviewServerAnswers $call): array => $call->reviewServerAnswers(), $this->reviewServerCalls()));
    }

    public function forgetTheReviewCredential(): void
    {
        foreach ($this->reviewServerCalls() as $call) {
            $call->forgetTheCredential();
        }
    }

    /**
     * @return list<ReviewServerAnswers>
     */
    private function reviewServerCalls(): array
    {
        return array_values(array_filter(array_map(static fn (DeferredResult $round): object => $round->getRawResult(), $this->rounds), static fn (object $call): bool => $call instanceof ReviewServerAnswers));
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $round = $this->tiers->invoke($this->answering, new TierRequest($input, $this->server instanceof ReviewServerAccess ? [...$options, ReviewServerAccess::OPTION => $this->server] : $options));
        $this->rounds[] = $round;

        return $round;
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->answering->provider->streams() ? new FallbackModelCatalog() : new UnstreamedModelCatalog();
    }
}
