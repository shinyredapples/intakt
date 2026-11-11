<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Infrastructure;

use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Core\Shadow\Contract\ProjectModes;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeWrites;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;
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

    // Only a live project posts a review, because a reviewer-for-loop project speaks to the loop through its published verdict alone and never merges, pushes, labels or approves.
    public function postReview(string $project, Change $change, ReviewComment $comment): void
    {
        if (ProjectMode::Live !== $this->modes->of($project)) {
            return;
        }

        $this->forge->postReview($project, $change, $comment);
    }

    // A shadow project publishes nothing, because its verdicts never count.
    public function publishVerdict(string $project, ChangeId $change, PublishedVerdict $verdict): void
    {
        if (ProjectMode::Shadow === $this->modes->of($project)) {
            return;
        }

        $this->forge->publishVerdict($project, $change, $verdict);
    }
}
