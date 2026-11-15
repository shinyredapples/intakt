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
    ) {
    }

    #[Override]
    public function hashes(): PromptHashes
    {
        return new PromptHashes(hash(self::HASH, $this->reviewer), hash(self::HASH, $this->judge));
    }
}
