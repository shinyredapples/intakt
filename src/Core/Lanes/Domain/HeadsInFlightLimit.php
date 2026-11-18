<?php

declare(strict_types=1);

namespace Intakt\Core\Lanes\Domain;

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
        if ($cap->heads > intdiv(PHP_INT_MAX, 2)) {
            throw HeadsInFlightLimitMustBePositive::twiceTooLarge($cap->heads);
        }

        return new self($cap->heads * 2);
    }

    // A count, not a list of waits, because heads awaiting owner approval, on hold or queued for rework have no HeadWait case but still conflict and use CI.
    public function admitsABuildBeside(int $headsInFlight): bool
    {
        return $headsInFlight < $this->heads;
    }
}
