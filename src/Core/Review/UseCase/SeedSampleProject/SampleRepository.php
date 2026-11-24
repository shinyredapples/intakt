<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\SeedSampleProject;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Review\Contract\SampleChange;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Built at the first start, because the fixture is plain files rather than a git repository (fixtures/sample-repository/README.md).
final readonly class SampleRepository
{
    public const string BASE = SampleChange::BASE;

    public const string BRANCH = SampleChange::CHANGE;

    // A fixed author and moment, so every install builds the same commits and a verdict names the same heads.
    private const array COMMITTER = [
        'GIT_AUTHOR_NAME' => 'Intakt sample',
        'GIT_AUTHOR_EMAIL' => 'sample@localhost',
        'GIT_AUTHOR_DATE' => '2026-11-01T00:00:00+00:00',
        'GIT_COMMITTER_NAME' => 'Intakt sample',
        'GIT_COMMITTER_EMAIL' => 'sample@localhost',
        'GIT_COMMITTER_DATE' => '2026-11-01T00:00:00+00:00',
    ];

    private const int TIMEOUT_SECONDS = 30;

    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/sample-repository')]
        private string $fixtures,
        private HardenedGit $hardened = new HardenedGit(),
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function buildOnce(string $target): bool
    {
        if ($this->filesystem->exists($target)) {
            return false;
        }

        // Built beside the target and renamed into place, so a start stopped halfway leaves no half repository that a later start would keep.
        $partial = $target.'.partial-'.bin2hex(random_bytes(4));

        try {
            $this->build($partial);
            $this->filesystem->rename($partial, $target);
        } finally {
            $this->filesystem->remove($partial);
        }

        return true;
    }

    private function build(string $directory): void
    {
        $this->filesystem->mkdir($directory);
        $this->init($directory);
        $this->commit($directory, 'base', 'Add the shop helpers');
        $this->git($directory, 'switch', '--quiet', '--create', self::BRANCH);
        $this->commit($directory, 'pull-request', 'Add shipping, a report and change the discount');
        $this->git($directory, 'switch', '--quiet', self::BASE);
    }

    private function init(string $directory): void
    {
        // The git folder is named, because git init refuses the pinned work tree without one; it is the work tree's own .git, so init records no core.worktree that would point at the partial directory after the rename.
        $call = $this->hardened->call($directory, '--git-dir=.git', 'init', '--quiet', '--initial-branch='.self::BASE);
        new Process([...$call->command], $call->directory, [...$call->environment], timeout: self::TIMEOUT_SECONDS)->mustRun();
    }

    private function commit(string $directory, string $tree, string $message): void
    {
        $this->filesystem->mirror($this->fixtures.'/'.$tree, $directory, options: ['override' => true]);
        $this->git($directory, 'add', '--all');
        $this->git($directory, 'commit', '--quiet', '--no-gpg-sign', '--message', $message);
    }

    private function git(string $directory, string ...$arguments): void
    {
        $call = $this->hardened->call($directory, ...$arguments);
        new Process([...$call->command], $call->directory, [...$call->environment, ...self::COMMITTER], timeout: self::TIMEOUT_SECONDS)->mustRun();
    }
}
