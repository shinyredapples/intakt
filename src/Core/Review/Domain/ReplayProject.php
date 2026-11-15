<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Doctrine\ORM\Mapping as ORM;

// A row per flagged project, because a project has no table of its own: it is the name its rounds are stored under.
#[ORM\Entity]
#[ORM\Table(name: 'review_replay_project')]
final readonly class ReplayProject
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private string $project,
    ) {
    }

    public function project(): string
    {
        return $this->project;
    }
}
