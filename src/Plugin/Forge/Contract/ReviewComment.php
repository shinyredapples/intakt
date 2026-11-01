<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use InvalidArgumentException;

final readonly class ReviewComment
{
    private const string REDACTED = '[redacted]';

    public function __construct(
        public string $body,
        public ReviewToken $token,
        public JobCredentials $credentials,
    ) {
        if ('' === trim($body)) {
            throw new InvalidArgumentException('A review comment has a body.');
        }
    }

    public function redactedBody(): string
    {
        // strtr replaces the longest value first, so a credential that contains another is redacted whole.
        return strtr($this->body, array_fill_keys($this->credentials->values, self::REDACTED));
    }
}
