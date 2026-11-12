<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

final readonly class RefusedHandBack
{
    public function __construct(
        public HandBackRefusal $refusal,
    ) {
    }

    public function reason(): string
    {
        return $this->refusal->reason();
    }
}
