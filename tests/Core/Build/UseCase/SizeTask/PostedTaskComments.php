<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\TaskComments;
use Override;

final class PostedTaskComments implements TaskComments
{
    /**
     * @var list<array{string, int, string}>
     */
    public array $posted = [];

    public ?ForgeWriteFailed $failure = null;

    #[Override]
    public function post(string $project, int $number, string $body): void
    {
        if ($this->failure instanceof ForgeWriteFailed) {
            throw $this->failure;
        }

        $this->posted[] = [$project, $number, $body];
    }
}
