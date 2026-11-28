<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;

final readonly class AccountPicked
{
    /**
     * @param list<PickedLeftOut> $leftOut
     */
    public function __construct(
        public Provider $provider,
        public ?string $picked,
        public array $leftOut,
    ) {
    }
}
