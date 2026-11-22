<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\PromptCut;
use RuntimeException;

// Intakt's own reason, never the model's, so the escalation can be recorded as failed without a verdict when the finding's code cannot reach the model.
final class CitedCodeNotShown extends RuntimeException
{
    public static function notInDiff(): self
    {
        return new self('No file the finding cites is in the diff, so its code cannot be shown.');
    }

    public static function overCeiling(int $bytes): self
    {
        return new self(sprintf('The diff of the files the finding cites holds %d bytes, over the prompt ceiling of %d.', $bytes, PromptCut::CEILING_BYTES));
    }
}
