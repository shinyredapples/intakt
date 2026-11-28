<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\SplitUmbrellas;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;

// The failure's text when the named task may not be built, because the caller names the issue and no other issue may stand in for it.
final readonly class TaskAdmission
{
    public function __construct(
        private IssueReads $issues,
        private SplitUmbrellas $umbrellas,
    ) {
    }

    public function taskOf(BuildTaskRequest $request): ListedIssue|string
    {
        try {
            $issue = $this->issues->readOpen($request->project, $request->issue);
        } catch (ForgeReadFailed|ForgeRateLimited $failure) {
            return $failure->getMessage();
        }

        if (!$issue instanceof ListedIssue) {
            return sprintf('project "%s" has no open issue %d', $request->project, $request->issue);
        }

        // Checked at admission as well as at intake, because an operator can name any open issue and the umbrella is never built: docs/design/05-architecture/03-choosing-work.md
        return $this->umbrellas->of($request->project)->contains($request->issue)
            ? sprintf('issue %d of project "%s" is split into parts, which are built in its place', $request->issue, $request->project)
            : $issue;
    }
}
