<?php

declare(strict_types=1);

namespace Intakt\Core\Lanes\Domain;

use Intakt\Core\Lanes\Contract\HeadWait;

final readonly class ReviewCap
{
    public function __construct(
        public int $heads,
    ) {
        if ($heads < 1) {
            throw ReviewCapMustBePositive::given($heads);
        }
    }

    /**
     * @param list<HeadWait> $headsInFlight
     */
    public function admitsABuildBeside(array $headsInFlight): bool
    {
        return $this->headsWaitingForAReviewer($headsInFlight) < $this->heads;
    }

    /**
     * @param list<HeadWait> $headsInFlight
     */
    private function headsWaitingForAReviewer(array $headsInFlight): int
    {
        return array_filter($headsInFlight, static fn (HeadWait $wait): bool => $wait->holdsTheReviewCap())
            |> count(...);
    }
}
