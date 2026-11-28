<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\PromptHashes;
use Intakt\Core\Review\Contract\ReviewPrompts;
use Override;

// The texts are the prompts' own constants, so the hash always follows the prompt a reviewer is given.
final readonly class HashedReviewPrompts implements ReviewPrompts
{
    private const string HASH = 'sha256';

    public function __construct(
        private string $reviewer = ReviewerPrompt::INSTRUCTIONS,
        private string $judge = JudgePrompt::INSTRUCTIONS,
        private string $writer = ReviewerPrompt::TECHNICAL_WRITER_INSTRUCTIONS,
    ) {
    }

    #[Override]
    public function hashes(): PromptHashes
    {
        return new PromptHashes($this->reviewerHash(), hash(self::HASH, $this->judge));
    }

    // Hashed over both texts, because the Technical writer reviews with its own instructions and a change to them must make its verdicts stale too; each part is hashed first, so the fixed-length parts cannot run into each other.
    private function reviewerHash(): string
    {
        return hash(self::HASH, hash(self::HASH, $this->reviewer).hash(self::HASH, $this->writer));
    }
}
