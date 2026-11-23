<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsTargetBranch
{
    public function __construct(
        private Workspace $workspace,
        private string $mergeBase,
        private DocsRatchetList $list,
    ) {
    }

    public function allowancesFor(string $path, DocsRatchetList $list): DocsAllowances
    {
        $shown = $this->workspace->git(['show', $this->mergeBase.':'.$path]);

        return new DocsAllowances($list, $this->list, $shown->succeeded() ? new MarkdownPage($shown->output) : null);
    }
}
