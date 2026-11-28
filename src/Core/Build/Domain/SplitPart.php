<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

final readonly class SplitPart
{
    /**
     * @param list<int> $dependsOn
     */
    public function __construct(
        public int $number,
        public string $title,
        public string $doneWhen,
        public string $verify,
        public string $files,
        public array $dependsOn,
    ) {
    }
}
