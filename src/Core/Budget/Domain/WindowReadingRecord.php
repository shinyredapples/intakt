<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;

#[ORM\Entity]
#[ORM\Table(name: 'budget_window_reading')]
#[ORM\Index(name: 'budget_window_reading_window', columns: ['provider', 'account', 'usage_window'])]
final class WindowReadingRecord
{
    // The database counts readings, so of two readings of a window taken in one second the higher position is the latest.
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    public private(set) int $position;

    #[ORM\Column(length: 32, enumType: Provider::class)]
    public readonly Provider $provider;

    #[ORM\Column(length: 255)]
    public readonly string $account;

    #[ORM\Column(length: 16, enumType: UsageWindow::class)]
    public readonly UsageWindow $usageWindow;

    #[ORM\Column]
    public readonly float $usedPercent;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    public readonly ?DateTimeImmutable $resetsAt;

    public function __construct(
        WindowReading $reading,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public readonly DateTimeImmutable $takenAt,
    ) {
        $this->provider = $reading->account->provider;
        $this->account = $reading->account->name;
        $this->usageWindow = $reading->window;
        $this->usedPercent = $reading->usage->usedPercent;
        $this->resetsAt = $reading->usage->resetsAt;
    }

    public function usage(): Usage
    {
        return new Usage($this->usedPercent, $this->resetsAt);
    }
}
