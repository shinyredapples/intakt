<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class GitServices
{
    public const string MIRRORS_DIRECTORY = 'intakt.git.mirrors_directory';
    public const string BRANCH_ARCHIVE_DIRECTORY = 'intakt.git.branch_archive_directory';
    public const string BRANCH_ARCHIVES = 'intakt.git.branch_archives';
    public const string SIGNING_KEY = 'intakt.git.signing_key';

    public const string PROTECTED_PATHS = 'intakt.git.protected_paths';
}
