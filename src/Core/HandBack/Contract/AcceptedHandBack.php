<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

// The head holds the bundle's commits with attribution stripped; the base is the pushed branch it was checked against, or null when the branch was not pushed yet, so a push can be made on that condition.
final readonly class AcceptedHandBack
{
    public function __construct(
        public ?string $base,
        public string $head,
        public int $rewrittenCommits,
    ) {
    }
}
