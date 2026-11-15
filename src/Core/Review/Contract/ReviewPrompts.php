<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

interface ReviewPrompts
{
    public function hashes(): PromptHashes;
}
