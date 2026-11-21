<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\OpenChange;
use LogicException;

#[ORM\Entity]
#[ORM\Table(name: 'watcher_seen_change')]
final class SeenChange
{
    // Text rather than a datetime column, because the platforms' datetime columns keep whole seconds and an update within the same second would then go unseen.
    private const string MOMENT = 'Y-m-d H:i:s.u';

    #[ORM\Column(length: 64)]
    private string $head;

    #[ORM\Column(length: 26)]
    private string $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        #[ORM\Id]
        #[ORM\Column(name: 'change_ref', length: 255)]
        private readonly string $change,
        OpenChange $seen,
    ) {
        $this->head = $seen->head->hash;
        $this->updatedAt = $this->momentOf($seen);
    }

    public function isStillAt(OpenChange $open): bool
    {
        return $this->head === $open->head->hash && $this->updatedAt === $this->momentOf($open);
    }

    public function see(OpenChange $open): void
    {
        $this->head = $open->head->hash;
        $this->updatedAt = $this->momentOf($open);
    }

    public function asOpenChange(): OpenChange
    {
        $moment = DateTimeImmutable::createFromFormat(self::MOMENT, $this->updatedAt, new DateTimeZone('UTC'));

        return new OpenChange(new ChangeId($this->change), new CommitId($this->head), false === $moment ? throw new LogicException(sprintf('The seen moment "%s" is not a moment.', $this->updatedAt)) : $moment);
    }

    public function project(): string
    {
        return $this->project;
    }

    public function change(): string
    {
        return $this->change;
    }

    private function momentOf(OpenChange $open): string
    {
        return $open->updatedAt->setTimezone(new DateTimeZone('UTC'))->format(self::MOMENT);
    }
}
