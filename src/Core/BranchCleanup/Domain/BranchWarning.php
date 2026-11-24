<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Git\Contract\BranchHead;
use LogicException;

// The warning tied to the head it named, because a branch that moved after it carries commits nobody was warned about.
#[ORM\Entity]
#[ORM\Table(name: 'branch_cleanup_warning')]
final class BranchWarning
{
    // Text, because the platforms' datetime columns keep whole seconds, as in the watcher's tables.
    private const string MOMENT = 'Y-m-d H:i:s.u';

    // A week of whole UTC days, so a weekly run a few seconds early still deletes what the run before warned about.
    private const int NOTICE_DAYS = 7;

    #[ORM\Column(name: 'warned_at', length: 26)]
    private string $warnedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $branch,
        #[ORM\Column(length: 64)]
        private string $head,
        DateTimeImmutable $warnedAt,
    ) {
        $this->warnedAt = $warnedAt->setTimezone(new DateTimeZone('UTC'))->format(self::MOMENT);
    }

    public function names(BranchHead $head): bool
    {
        return $this->branch === $head->branch && $this->head === $head->commit;
    }

    public function allowsDeletionAt(DateTimeImmutable $now): bool
    {
        $today = $now->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);

        return $this->warnedAt()->setTime(0, 0)->modify(sprintf('+%d days', self::NOTICE_DAYS)) <= $today;
    }

    public function deletableFrom(): DateTimeImmutable
    {
        return $this->warnedAt()->setTime(0, 0)->modify(sprintf('+%d days', self::NOTICE_DAYS));
    }

    public function project(): string
    {
        return $this->project;
    }

    public function branch(): string
    {
        return $this->branch;
    }

    public function head(): string
    {
        return $this->head;
    }

    public function warnedAt(): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(self::MOMENT, $this->warnedAt, new DateTimeZone('UTC'));

        return false === $parsed ? throw new LogicException('A stored moment is unreadable.') : $parsed;
    }
}
