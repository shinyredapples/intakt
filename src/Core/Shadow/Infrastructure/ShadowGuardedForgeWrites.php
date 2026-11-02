<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Infrastructure;

use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ForgeWrites;
use Intakt\Plugin\Forge\Contract\ReviewComment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

final readonly class ShadowGuardedForgeWrites implements ForgeWrites
{
    /**
     * @param array<string, string> $modes
     */
    public function __construct(
        #[AutowireDecorated]
        private ForgeWrites $forge,
        #[Autowire('%intakt.shadow.modes%')]
        private array $modes,
    ) {
    }

    public function postReview(string $project, Change $change, ReviewComment $comment): void
    {
        if ($this->isShadow($project)) {
            return;
        }

        $this->forge->postReview($project, $change, $comment);
    }

    private function isShadow(string $project): bool
    {
        return ProjectMode::Shadow === ProjectMode::from($this->modes[$project] ?? ProjectMode::Live->value);
    }
}
