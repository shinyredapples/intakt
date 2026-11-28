<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\GitHubForge\Contract\TaskComments;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TaskComments::class)]
final readonly class GitHubTaskComments implements TaskComments
{
    public function __construct(private GitHubIssueComments $comments)
    {
    }

    #[Override]
    public function post(string $project, int $number, string $body): void
    {
        $this->comments->post(new TaskIssue($project, $number), $body);
    }
}
