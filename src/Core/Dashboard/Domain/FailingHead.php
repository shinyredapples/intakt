<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class FailingHead
{
    public bool $handedOver;

    public function __construct(
        public string $project,
        public string $change,
        public HeadFault $fault,
    ) {
        $this->handedOver = false;
    }

    public function handedOver(): self
    {
        return clone ($this, ['handedOver' => true]);
    }

    public function withCause(string $cause): self
    {
        return clone ($this, ['fault' => new HeadFault($cause, $this->fault->failures, $this->fault->retryAt)]);
    }
}
