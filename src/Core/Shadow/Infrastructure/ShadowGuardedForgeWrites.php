<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Infrastructure;

use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Core\Shadow\Contract\ProjectModes;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ForgeWrites;
use Intakt\Plugin\Forge\Contract\ReviewComment;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

final readonly class ShadowGuardedForgeWrites implements ForgeWrites
{
    public function __construct(
        #[AutowireDecorated]
        private ForgeWrites $forge,
        private ProjectModes $modes,
    ) {
    }

    public function postReview(string $project, Change $change, ReviewComment $comment): void
    {
        if (ProjectMode::Shadow === $this->modes->of($project)) {
            return;
        }

        $this->forge->postReview($project, $change, $comment);
    }
}
