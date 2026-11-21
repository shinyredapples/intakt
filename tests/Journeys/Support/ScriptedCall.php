<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

final readonly class ScriptedCall
{
    public function __construct(
        public int $number,
        public int $pid,
        public int $processStart,
        public float $startedAt,
        public ?float $endedAt,
    ) {
    }
}
