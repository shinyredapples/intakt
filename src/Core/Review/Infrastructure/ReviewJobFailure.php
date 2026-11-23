<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// The reviewer gave no answer, so a caller treats it as a failed reviewer, never as an approval.
final class ReviewJobFailure extends RuntimeException implements ReportableFailure
{
    public static function withoutImage(string $project): self
    {
        return new self(sprintf('The project "%s" has no review job image; give it a review_job section with an image.', $project));
    }

    public static function withoutCli(Provider $provider): self
    {
        return new self(sprintf('No review job runs a reviewer on the provider %s; only claude_code and codex have a CLI in the review image.', $provider->value));
    }

    public static function refused(string $reason): self
    {
        return new self(sprintf('The review job refused to run the reviewer: %s.', $reason));
    }

    public static function outOfMemory(): self
    {
        return new self('The review job ran out of memory.');
    }

    public static function outOfTime(): self
    {
        return new self('The review job ran out of time.');
    }

    public static function withoutResult(): self
    {
        return new self('The review job left an output the runner refuses to read.');
    }

    public static function withoutCliExit(Provider $provider): self
    {
        return new self(sprintf('The review job did not report how the reviewer\'s CLI %s exited.', $provider->value));
    }

    public static function cliFailed(Provider $provider, int $status): self
    {
        return new self(sprintf('The reviewer\'s CLI %s exited with %d.', $provider->value, $status));
    }

    public static function unreadableAnswer(Provider $provider): self
    {
        return new self(sprintf('The reviewer\'s CLI %s gave no answer the review job can read.', $provider->value));
    }
}
