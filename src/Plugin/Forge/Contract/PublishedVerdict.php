<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Platform\Contract\Redaction;
use InvalidArgumentException;

// Null comment for a head Intakt could not review, because the comment's verdict is only ever approve or changes.
final readonly class PublishedVerdict
{
    public function __construct(
        public CommitId $head,
        public HeadStatus $status,
        public string $description,
        public ?string $comment,
    ) {
        if ('' === trim($description)) {
            throw new InvalidArgumentException('A published verdict has a description.');
        }

        if (null !== $comment && '' === trim($comment)) {
            throw new InvalidArgumentException('A published verdict\'s comment is not blank.');
        }
    }

    // Redacted with no job credentials, because the reviewers ran no job: what is left to catch are credential headers, URL userinfo and secret shapes.
    public function redactedComment(): ?string
    {
        return null === $this->comment ? null : new Redaction()->redact($this->comment);
    }
}
