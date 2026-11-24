<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A task's branch is `<type>/<number>` or `<type>/<number>-<words>`, as `feat/12-add-intake`; only the number is matched, because the words come from a title that may have changed since the branch was cut.
#[Exclude]
final readonly class TaskBranchName
{
    private const string FORM = '~^[a-z]+/(?<task>[1-9][0-9]*)(?:-|$)~';

    public static function names(string $branch, int $task): bool
    {
        return 1 === preg_match(self::FORM, $branch, $found) && $found['task'] === (string) $task;
    }
}
