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

// Kept from the listing until the head is settled, because an unchanged listing names no pull request, so a head still waiting for its CI or for a start that failed is read again from here.
#[ORM\Entity]
#[ORM\Table(name: 'watcher_pending_head')]
final class PendingHead
{
    // Text because the platforms' datetime columns keep whole seconds, as in SeenChange.
    private const string MOMENT = 'Y-m-d H:i:s.u';

    #[ORM\Id]
    #[ORM\Column(name: 'change_ref', length: 255)]
    private readonly string $change;

    #[ORM\Column(length: 64)]
    private string $head;

    #[ORM\Column(name: 'updated_at', length: 26)]
    private string $updatedAt;

    #[ORM\Column(name: 'read_again_at', length: 26)]
    private string $readAgainAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        OpenChange $waiting,
        DateTimeImmutable $readAgainAt,
    ) {
        $this->change = $waiting->id->value;
        $this->wait($waiting, $readAgainAt);
    }

    public function wait(OpenChange $waiting, DateTimeImmutable $readAgainAt): void
    {
        $this->head = $waiting->head->hash;
        $this->updatedAt = $this->format($waiting->updatedAt);
        $this->readAgainAt = $this->format($readAgainAt);
    }

    public function project(): string
    {
        return $this->project;
    }

    public function change(): string
    {
        return $this->change;
    }

    public function isAt(OpenChange $open): bool
    {
        return $this->head === $open->head->hash;
    }

    public function isDueAt(DateTimeImmutable $now): bool
    {
        return $this->readAgainAt <= $this->format($now);
    }

    public function openChange(): OpenChange
    {
        return new OpenChange(new ChangeId($this->change), new CommitId($this->head), $this->parse($this->updatedAt));
    }

    private function format(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(self::MOMENT);
    }

    private function parse(string $moment): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(self::MOMENT, $moment, new DateTimeZone('UTC'));

        return false === $parsed ? throw new LogicException('A stored moment is unreadable.') : $parsed;
    }
}
