<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunRegressionTests;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\RunCheckJob\ChangedFile;
use Intakt\Core\HandBack\UseCase\RunCheckJob\RawDiffEntries;
use Intakt\Plugin\Gate\Contract\Gate;

// The regression tests are the test files the diff adds or changes, never a list the builder gives, because a builder's claim proves nothing.
final readonly class RegressionTestFiles
{
    private const string REGULAR_FILE_MODE = '100';

    public function __construct(
        private RepositoryGit $git,
        private Gate $gate,
    ) {
    }

    /**
     * @throws HandBackNotChecked
     */
    public function baseOf(string $repository, string $head): string
    {
        // The merge base, because a test main gained after the branch started is not this change's.
        $main = $this->git->output($repository, ['symbolic-ref', '--quiet', 'HEAD']);

        return $this->git->output($repository, ['merge-base', $main, $head]);
    }

    /**
     * @throws HandBackNotChecked
     */
    public function in(string $repository, string $base, string $head): Paths
    {
        $changed = RawDiffEntries::of($this->git->exactOutput($repository, ['diff', '--raw', '-z', '-M', '--no-abbrev', $base, $head, '--']));
        $tests = array_filter($changed, $this->isRegressionTest(...));

        return new Paths(...array_values(array_unique(array_map(static fn (ChangedFile $file): string => $file->path(), $tests))));
    }

    private function isRegressionTest(ChangedFile $file): bool
    {
        // A deleted file, a submodule and a link have no test to run, because there is no file content to execute; a rename counts under its new path.
        if ($file->isGone() || !str_starts_with($file->newMode, self::REGULAR_FILE_MODE)) {
            return false;
        }

        return $this->gate->isTestFile($file->path());
    }
}
