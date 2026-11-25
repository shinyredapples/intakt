<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

use DateInterval;
use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\CommitId;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The warning tied to the head it named, because a branch that moved after it carries commits nobody was warned about.
#[Exclude]
final readonly class BranchWarning
{
    private const string NOTICE = 'PT168H';

    public function __construct(
        public string $branch,
        public CommitId $head,
        public DateTimeImmutable $warnedAt,
    ) {
    }

    // A full 7 x 24 hours, never rounded to whole days: a warning late in a day must not allow deletion a few minutes short of the week.
    // Sibling branches often share a head, so the warning counts only for the branch it named.
    public function allowsDeletionAt(string $branch, CommitId $head, DateTimeImmutable $now): bool
    {
        return $this->branch === $branch && $this->head->equals($head) && $this->warnedAt->add(new DateInterval(self::NOTICE)) <= $now;
    }
}
