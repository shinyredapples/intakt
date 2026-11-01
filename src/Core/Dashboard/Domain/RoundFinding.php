<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class RoundFinding
{
    public JudgeCall $call;

    public function __construct(
        public string $id,
        public string $severity,
        public string $text,
    ) {
        $this->call = JudgeCall::NotActedOn;
    }

    public function calledBy(JudgeCall $call): self
    {
        return clone ($this, ['call' => $call]);
    }
}
