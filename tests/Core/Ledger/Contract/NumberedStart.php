<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger\Contract;

final readonly class NumberedStart
{
    public function __construct(
        public int $number,
        public string $change,
    ) {
    }
}
