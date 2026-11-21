<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Review\Contract\EscalationOutcome;

// One row per finding key, so a finding on unchanged files never holds two verdicts; any edit of a named file gives another key and so no row.
#[ORM\Entity]
#[ORM\Table(name: 'kept_verdict')]
final readonly class KeptVerdict
{
    public function __construct(
        // Named finding_key, because KEY is a reserved word in MySQL and MariaDB.
        #[ORM\Id]
        #[ORM\Column(name: 'finding_key', length: 255)]
        public string $key,
        #[ORM\Column(length: 16, enumType: EscalationOutcome::class)]
        public EscalationOutcome $outcome,
        #[ORM\Column(type: 'text')]
        public string $reasons,
        #[ORM\Column(length: 36)]
        public string $round,
        #[ORM\Column(name: 'recorded_at', type: 'utc_datetime_immutable')]
        public DateTimeImmutable $recordedAt,
    ) {
    }
}
