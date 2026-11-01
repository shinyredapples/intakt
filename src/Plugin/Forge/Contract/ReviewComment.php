<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use InvalidArgumentException;

final readonly class ReviewComment
{
    public function __construct(
        public string $body,
        public ReviewToken $token,
    ) {
        if ('' === trim($body)) {
            throw new InvalidArgumentException('A review comment has a body.');
        }
    }
}
