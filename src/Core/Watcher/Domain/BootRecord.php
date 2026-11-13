<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\BootState;

#[ORM\Entity]
#[ORM\Table(name: 'watcher_boot_result')]
final class BootRecord
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private readonly string $project;

    #[ORM\Id]
    #[ORM\Column(name: 'main_commit', length: 64)]
    private readonly string $main;

    #[ORM\Id]
    #[ORM\Column(name: 'tool_fingerprint', length: 255)]
    private readonly string $toolFingerprint;

    #[ORM\Column(length: 16, enumType: BootState::class)]
    private readonly BootState $state;

    #[ORM\Column(type: 'text')]
    private readonly string $reason;

    public function __construct(
        BootKey $key,
        BootOutcome $outcome,
        #[ORM\Column(name: 'recorded_at', type: 'utc_datetime_immutable')]
        private readonly DateTimeImmutable $recordedAt,
    ) {
        $this->project = $key->project;
        $this->main = $key->main->hash;
        $this->toolFingerprint = $key->toolFingerprint;
        $this->state = $outcome->state;
        $this->reason = $outcome->reason;
    }

    public function outcome(): BootOutcome
    {
        return match ($this->state) {
            BootState::Booted => BootOutcome::booted(),
            BootState::Failed => BootOutcome::failed($this->reason),
            BootState::Crashed => BootOutcome::crashed($this->reason),
        };
    }

    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
