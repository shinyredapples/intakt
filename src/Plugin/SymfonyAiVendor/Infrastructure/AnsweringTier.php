<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;

// A tier with the model chosen to answer it, because a call's price follows that model and not the one the vendor's answer names.
final readonly class AnsweringTier
{
    public function __construct(
        public Tier $tier,
        public ResolvedModel $answering,
    ) {
    }
}
