<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Platform\Contract\Redaction;
use InvalidArgumentException;

final readonly class ReviewComment
{
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
        return new Redaction()->redact($this->body, ...$this->credentials);
    }
}
