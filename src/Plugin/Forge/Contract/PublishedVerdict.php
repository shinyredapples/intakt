<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use InvalidArgumentException;

// Null comment for a head Intakt could not review, because the comment's verdict is only ever approve or changes. The owner asks are comments of their own, so a retry whose review comment is already on the forge still posts an ask the earlier try did not carry.
final readonly class PublishedVerdict
{
    /**
     * @param list<string> $ownerAsks
     */
    public function __construct(
        public CommitId $head,
        public HeadStatus $status,
        public string $description,
        public ?string $comment,
        public ReviewToken $token,
        public array $ownerAsks = [],
    ) {
        if ('' === trim($description)) {
            throw new InvalidArgumentException('A published verdict has a description.');
        }

        if (null !== $comment && '' === trim($comment)) {
            throw new InvalidArgumentException('A published verdict\'s comment is not blank.');
        }
    }

    // A list of none or one, so a forge posts what is there without a branch of its own; not redacted again, because its author redacts each line alone and a pass over the whole text could carry a redaction with no end into the verdict block.
    /**
     * @return list<string>
     */
    public function comments(): array
    {
        return null === $this->comment ? [] : [$this->comment];
    }
}
