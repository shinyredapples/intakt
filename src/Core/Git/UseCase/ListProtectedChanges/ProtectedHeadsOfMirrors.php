<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\ListProtectedChanges;

use Intakt\Core\Git\Contract\GitServices;
use Intakt\Core\Git\Contract\ProtectedChanges;
use Intakt\Core\Git\Contract\ProtectedHeads;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\Domain\ProtectedPathRefused;
use Intakt\Core\Git\Domain\ProtectedPaths;
use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ProtectedHeadsOfMirrors implements ProtectedHeads
{
    /**
     * @param array<string, list<string>> $protectedPaths
     */
    public function __construct(
        private ListProtectedChanges $changes,
        private Mirrors $mirrors,
        #[Autowire(param: GitServices::PROTECTED_PATHS)]
        private array $protectedPaths,
    ) {
    }

    #[NoDiscard]
    public function of(string $project, string $head): ProtectedChanges
    {
        try {
            return $this->checked($project, $head);
        } catch (ProjectNameRefused|CommitNameRefused|ProtectedPathRefused $refused) {
            return ProtectedChanges::unreadable($refused->getMessage());
        }
    }

    // Refusals thrown, because the command tells a mistyped argument apart from a checked head.
    /**
     * @throws ProjectNameRefused
     * @throws CommitNameRefused
     * @throws ProtectedPathRefused
     */
    #[NoDiscard]
    public function checked(string $project, string $head): ProtectedChanges
    {
        return $this->changes->of($this->mirrors->pathOf($project), $head, new ProtectedPaths($this->protectedPaths[$project] ?? []));
    }
}
