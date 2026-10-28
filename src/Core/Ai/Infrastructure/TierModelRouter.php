<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelRouter\RoutingDecision;
use Symfony\AI\Platform\ModelRouterInterface;
use Symfony\AI\Platform\ProviderInterface;

// A call names a tier and its project instead of a model, so the project's config decides the provider and the model.
final readonly class TierModelRouter implements ModelRouterInterface
{
    public const string PROJECT_OPTION = 'project';

    public function __construct(
        private TierResolver $resolver,
    ) {
    }

    // The interface's input and options arrive as one list because Symfony AI fixes four parameters and Intakt's parameter-limit gate allows three; the input is not read here.
    public function resolve(string|Model $model, iterable $providers, mixed ...$request): RoutingDecision
    {
        $options = $this->options($request);
        $project = $this->project($options);
        $tier = $this->tier($model);
        $resolved = $this->resolver->resolve($project, $tier);
        unset($options[self::PROJECT_OPTION]);

        return new RoutingDecision(
            $this->provider($providers, $resolved->provider),
            $resolved->model,
            $options,
            sprintf('Project "%s" maps the "%s" tier to %s.', $project, $tier->value, $this->describe($resolved)),
        );
    }

    /**
     * @param array<int|string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function options(array $request): array
    {
        $options = $request[1] ?? $request['options'] ?? [];

        if (!is_array($options)) {
            throw new InvalidArgumentException('The options of a tier call must be an array.');
        }

        /** @var array<string, mixed> $named */
        $named = $options;

        return $named;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function project(array $options): string
    {
        $project = $options[self::PROJECT_OPTION] ?? null;

        return is_string($project) ? $project : throw new InvalidArgumentException(sprintf('A tier call needs the project as the "%s" option.', self::PROJECT_OPTION));
    }

    private function tier(string|Model $model): Tier
    {
        $name = $model instanceof Model ? $model->getName() : $model;
        $tiers = array_column(Tier::cases(), 'value');

        return Tier::tryFrom($name) ?? throw new ModelNotFoundException(sprintf('"%s" is not a capability tier: use %s or %s.', $name, implode(', ', array_slice($tiers, 0, -1)), array_last($tiers)));
    }

    /**
     * @param iterable<ProviderInterface> $providers
     */
    private function provider(iterable $providers, Provider $provider): ProviderInterface
    {
        foreach ($providers as $candidate) {
            if ($candidate->getName() === $provider->value) {
                return $candidate;
            }
        }

        throw new ModelNotFoundException(sprintf('The platform has no provider "%s".', $provider->value));
    }

    private function describe(ResolvedModel $resolved): string
    {
        return sprintf('"%s" on "%s"', $resolved->model, $resolved->provider->value);
    }
}
