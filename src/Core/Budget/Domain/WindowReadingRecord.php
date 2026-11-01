<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\UsageWindow;

#[ORM\Entity]
#[ORM\Table(name: 'budget_window_reading')]
#[ORM\Index(name: 'budget_window_reading_window', columns: ['provider', 'account', 'usage_window'])]
final class WindowReadingRecord
{
    // The database counts readings, so the latest one of a window is the highest position even when two arrive in one second.
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    public private(set) int $position;

    public function __construct(
        #[ORM\Column(length: 32, enumType: Provider::class)]
        public readonly Provider $provider,
        #[ORM\Column(length: 255)]
        public readonly string $account,
        #[ORM\Column(length: 16, enumType: UsageWindow::class)]
        public readonly UsageWindow $usageWindow,
        #[ORM\Column]
        public readonly float $usedPercent,
        #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
        public readonly ?DateTimeImmutable $resetsAt,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public readonly DateTimeImmutable $takenAt,
    ) {
    }
}
