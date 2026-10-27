<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class Finding
{
    public function __construct(
        public string $id,
        public Severity $severity,
        public ?string $file,
        public ?int $line,
        public ?string $symbol,
        public string $text,
        public FixableBy $fixableBy,
        public bool $repeat,
        public ?string $requirement,
        public ?Evidence $evidence,
    ) {
    }
}
