<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Infrastructure;

use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Core\Shadow\Contract\ProjectModes;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ForgeWrites;
use Intakt\Plugin\Forge\Contract\ReviewComment;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

final readonly class ModeGuardedForgeWrites implements ForgeWrites
{
    public function __construct(
        #[AutowireDecorated]
        private ForgeWrites $forge,
        private ProjectModes $modes,
    ) {
    }

    public function postReview(string $project, Change $change, ReviewComment $comment): void
    {
        if (!$this->writesFor($project)) {
            return;
        }

        $this->forge->postReview($project, $change, $comment);
    }

    // Only a live project writes, because a reviewer-for-loop project publishes nothing until its review is posted (issue #1251, part 2) and never merges, pushes or labels.
    private function writesFor(string $project): bool
    {
        return ProjectMode::Live === $this->modes->of($project);
    }
}
