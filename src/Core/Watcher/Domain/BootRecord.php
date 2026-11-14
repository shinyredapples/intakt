<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\BootState;
use Intakt\Plugin\Forge\Contract\CommitId;

// Only read through the entity manager: a record is written with one insert on the connection, because a flush on the ledger's shared manager closes it when two workers store the same key.
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
final readonly class BootRecord
{
    public const string TABLE = 'watcher_boot_result';

    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private string $project;

    #[ORM\Id]
    #[ORM\Column(name: 'main_commit', length: 64)]
    private string $main;

    #[ORM\Id]
    #[ORM\Column(name: 'tool_fingerprint', length: 255)]
    private string $toolFingerprint;

    #[ORM\Column(length: 16, enumType: BootState::class)]
    private BootState $state;

    #[ORM\Column(type: 'text')]
    private string $reason;

    // Kept with the row, because the owner alert of a failed main must survive a ledger that refuses it and still come only once.
    #[ORM\Column(name: 'alert_pending', options: ['default' => false])]
    private bool $alertPending;

    public function __construct(
        BootKey $key,
        BootOutcome $outcome,
        #[ORM\Column(name: 'recorded_at', type: 'utc_datetime_immutable')]
        private DateTimeImmutable $recordedAt,
    ) {
        $this->project = $key->project;
        $this->main = $key->main->hash;
        $this->toolFingerprint = $key->toolFingerprint;
        $this->state = $outcome->state;
        $this->reason = $outcome->reason;
        $this->alertPending = BootState::Failed === $outcome->state;
    }

    /**
     * @return array{project: string, main_commit: string, tool_fingerprint: string, state: string, reason: string, alert_pending: bool, recorded_at: DateTimeImmutable}
     */
    public function row(): array
    {
        return ['project' => $this->project, 'main_commit' => $this->main, 'tool_fingerprint' => $this->toolFingerprint, 'state' => $this->state->value, 'reason' => $this->reason, 'alert_pending' => $this->alertPending, 'recorded_at' => $this->recordedAt];
    }

    /**
     * @return array{alert_pending: string, recorded_at: string}
     */
    public static function rowTypes(): array
    {
        return ['alert_pending' => 'boolean', 'recorded_at' => 'utc_datetime_immutable'];
    }

    /**
     * @return array{project: string, main_commit: string, tool_fingerprint: string}
     */
    public static function keyRow(BootKey $key): array
    {
        return ['project' => $key->project, 'main_commit' => $key->main->hash, 'tool_fingerprint' => $key->toolFingerprint];
    }

    public function alertPending(): bool
    {
        return $this->alertPending;
    }

    public function key(): BootKey
    {
        return new BootKey($this->project, new CommitId($this->main), $this->toolFingerprint);
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
