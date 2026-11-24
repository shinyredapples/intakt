<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Provider;

final readonly class TeamRole
{
    /**
     * @param list<Provider> $providers
     */
    public function __construct(
        public TeamRoleName $name,
        public array $providers,
    ) {
    }
}
