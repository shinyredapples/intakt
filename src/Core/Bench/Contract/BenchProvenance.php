<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Contract;

use Intakt\Core\Review\Contract\PromptHashes;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What a result file names so two runs can be compared: the code, the prompts and the bench that produced it.
#[Exclude]
final readonly class BenchProvenance
{
    public function __construct(
        public string $intaktVersion,
        public PromptHashes $prompts,
        public string $benchRepositoryHash,
    ) {
    }
}
