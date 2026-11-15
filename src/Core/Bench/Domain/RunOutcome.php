<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

use Intakt\Core\Review\Contract\Finding;

final readonly class RunOutcome
{
    /**
     * @param list<Finding> $findings
     */
    private function __construct(
        public array $findings,
        public ?string $harnessFailure,
    ) {
    }

    /**
     * @param list<Finding> $findings
     */
    public static function completed(array $findings): self
    {
        return new self($findings, null);
    }

    public static function failed(string $harnessFailure): self
    {
        return new self([], $harnessFailure);
    }

    public function completedNormally(): bool
    {
        return null === $this->harnessFailure;
    }
}
