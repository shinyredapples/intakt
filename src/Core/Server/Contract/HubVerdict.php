<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The one judgement of a hub reading, so the board, the doctor and the container's health command never disagree about the hub.
#[Exclude]
final readonly class HubVerdict
{
    private function __construct(
        public HubState $state,
        public string $detail,
    ) {
    }

    public static function of(HubHealth|EndpointDown $hub): self
    {
        if ($hub instanceof EndpointDown) {
            return new self(HubState::Down, $hub->reason);
        }

        return new self(HubHealth::Healthy === $hub ? HubState::Healthy : HubState::Unhealthy, $hub->value);
    }

    public function problem(): ?string
    {
        return match ($this->state) {
            HubState::Healthy => null,
            HubState::Unhealthy => 'the hub reports unhealthy',
            HubState::Down => 'hub down ('.$this->detail.')',
        };
    }
}
