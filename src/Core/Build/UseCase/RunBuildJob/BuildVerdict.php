<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

enum BuildVerdict: string
{
    case Built = 'built';
    case NoCommits = 'no_commits';
    case NoPullRequestText = 'no_pull_request_text';
    case MemoryKilled = 'memory_killed';
    case TimedOut = 'timed_out';
    case NoResult = 'no_result';

    public function reason(): string
    {
        return match ($this) {
            self::Built => 'the build handed back a bundle and the pull request text',
            self::NoCommits => 'the build made no commit on the task\'s branch',
            self::NoPullRequestText => 'the build wrote no pull request text to PR.md',
            self::MemoryKilled => 'the build job ran out of memory',
            self::TimedOut => 'the build job ran out of time',
            self::NoResult => 'the build job left an output the runner refuses to read',
        };
    }
}
