<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Doctrine\ORM\Mapping as ORM;

// Apart from the listing's rows, because a failed task that is closed afterwards must still hold the tasks that wait on it.
#[ORM\Entity]
#[ORM\Table(name: 'work_source_failure')]
final readonly class TaskFailure
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private string $project,
        #[ORM\Id]
        #[ORM\Column]
        private int $number,
        #[ORM\Column(type: 'text')]
        private string $reason,
    ) {
    }

    public function project(): string
    {
        return $this->project;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
