<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use Intakt\Core\Review\Contract\Finding;

final readonly class Rework
{
    /**
     * @param list<Finding> $actList
     */
    public function __construct(
        public array $actList,
        public ?string $ciExcerpt,
        public string $publishedText,
    ) {
    }
}
