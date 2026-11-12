<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

final readonly class Task
{
    public function __construct(
        public int $number,
        public string $title,
    ) {
    }
}
