<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

// One provider and its model for a FailoverPlatform, which passes every platform the same model, so each candidate sends its own; excluded from the container because ProviderFailoverPlatform builds them per call.
#[Exclude]
final readonly class ProviderCandidate implements PlatformInterface
{
    public function __construct(
        private ResolvedModel $target,
        private PlatformInterface $platform,
        private FailoverTrail $trail,
    ) {
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        // FailoverPlatform moves on after any failure, but only a provider that is out may hand the call to another one: a failure of the call itself (a refused request) would let its sender pick the reviewer.
        $failure = $this->trail->hardFailure();
        if ($failure instanceof Throwable) {
            throw $failure;
        }

        try {
            $result = $this->platform->invoke($this->target->model, $input, $options);
        } catch (EveryAccountCoolingDown $out) {
            $this->trail->markOut($out);

            throw $out;
        } catch (Throwable $failed) {
            $this->trail->halt($failed);

            throw $failed;
        }

        $this->trail->answer($this->target->provider);

        return $result;
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform->getModelCatalog();
    }
}
