<?php

declare(strict_types=1);

namespace Intakt\Core\Lanes\Domain;

use Intakt\Core\Lanes\Contract\HeadWait;

final readonly class HeadsInFlightLimit
{
    public function __construct(
        public int $heads,
    ) {
        if ($heads < 1) {
            throw HeadsInFlightLimitMustBePositive::given($heads);
        }
    }

    // Heads waiting for CI or landing still conflict with main and use CI, but they leave the reviewer free, so the limit sits above the cap.
    public static function twiceThe(ReviewCap $cap): self
    {
        return new self($cap->heads * 2);
    }

    /**
     * @param list<HeadWait> $headsInFlight
     */
    public function admitsABuildBeside(array $headsInFlight): bool
    {
        return count($headsInFlight) < $this->heads;
    }
}
