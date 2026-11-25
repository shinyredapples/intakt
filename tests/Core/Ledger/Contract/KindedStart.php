<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger\Contract;

final readonly class KindedStart
{
    public function __construct(
        public StartKind $kind,
    ) {
    }
}
