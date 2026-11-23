<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Closure;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Only text and labels from accounts with write access steer the loop, so the rules keep whether author and last editor have such access and the labels such an account applied last, in the order of those applications.
#[Exclude]
final readonly class Vouching
{
    /**
     * @param list<string> $labels
     */
    private function __construct(
        public bool $trusted,
        public array $labels,
    ) {
    }

    // The last editor counts, not a comment, because the text that steers is the one they saved: an edit by an account without write access holds the item until such an account saves it again.
    /**
     * @param Closure(string): bool $steers
     */
    public static function of(string $author, IssueHistory $history, Closure $steers): self
    {
        if (!$steers($author) || !$steers($history->lastEditor)) {
            return self::untrusted();
        }

        $vouched = array_filter($history->labels, static fn (LabelApplication $application): bool => $steers($application->appliedBy));

        return new self(true, array_values(array_map(static fn (LabelApplication $application): string => $application->label, $vouched)));
    }

    public static function untrusted(): self
    {
        return new self(false, []);
    }

    // Skips the write-access check, because only a stored row comes back this way and of() already checked it before the row was saved.
    /**
     * @param list<string> $labels
     */
    public static function restored(array $labels): self
    {
        return new self(true, $labels);
    }
}
