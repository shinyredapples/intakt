<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The one judgement of the Mercure hub, shared by the board's infrastructure row and every health check, so they never disagree.
#[Exclude]
final readonly class HubVerdict
{
    private function __construct(
        public ?string $problem,
    ) {
    }

    public static function of(HubHealth|EndpointDown $hub): self
    {
        return new self(match (true) {
            $hub instanceof EndpointDown => 'hub down ('.$hub->reason.')',
            HubHealth::Unhealthy === $hub => 'the hub reports unhealthy',
            HubHealth::Healthy === $hub => null,
        });
    }

    public function passes(): bool
    {
        return null === $this->problem;
    }
}
