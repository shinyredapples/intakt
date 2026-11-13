<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\HandBack\Contract\HandBackRefusal;

// The reason is the refusal's fixed text, so what goes back to the builder or over to the next hands holds no value from the hand-back.
final readonly class FailedHandBack
{
    public function __construct(
        public HandBackRefusal $refusal,
        public HandBackRoute $route,
    ) {
    }

    public function reason(): string
    {
        return $this->refusal->reason();
    }
}
