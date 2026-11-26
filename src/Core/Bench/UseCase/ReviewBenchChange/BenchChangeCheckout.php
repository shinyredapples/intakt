<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\UseCase\ReviewBenchChange;

use Intakt\Core\Bench\Domain\BenchChange;
use Intakt\Core\Bench\Domain\ChangeFiles;
use Intakt\Core\Bench\Domain\Expectations;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

// Builds a repository of its own for each run, because the reviewers read the checkout and a run must start from the base commit whatever an earlier run left.
final readonly class BenchChangeCheckout
{
    private const string BASE = 'main';
    private const string CHECK_COMMAND = 'bin/check';
    private const int TIMEOUT_SECONDS = 30;

    // A fixed author and moment, so every run builds the same commits.
    private const array COMMITTER = [
        'GIT_AUTHOR_NAME' => 'Intakt bench',
        'GIT_AUTHOR_EMAIL' => 'bench@localhost',
        'GIT_AUTHOR_DATE' => '2026-11-01T00:00:00+00:00',
        'GIT_COMMITTER_NAME' => 'Intakt bench',
        'GIT_COMMITTER_EMAIL' => 'bench@localhost',
        'GIT_COMMITTER_DATE' => '2026-11-01T00:00:00+00:00',
    ];

    public function __construct(
        private Panels $panels,
        #[Autowire('%kernel.project_dir%/fixtures/sample-repository')]
        private string $fixtures,
        private HardenedGit $hardened = new HardenedGit(),
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        foreach (new Finder()->files()->in($this->fixtures.'/bench')->depth('== 1')->name('expectations.json')->sortByName() as $file) {
            $names[] = $file->getRelativePath();
        }

        return $names;
    }

    /**
     * @throws BenchChangeNotFound
     */
    public function open(string $name, string $project, string $directory): BenchChange
    {
        $folder = $this->fixtures.'/bench/'.$name;
        if (1 !== preg_match('/^[a-z0-9][a-z0-9-]*\z/', $name) || !$this->filesystem->exists($folder.'/expectations.json')) {
            throw BenchChangeNotFound::named($name);
        }

        $task = $this->filesystem->readFile($folder.'/task.md');
        $diff = $this->build($directory, $name, $folder.'/tree');
        $range = new CommitRange(new CommitId(trim($this->git($directory, 'rev-parse', self::BASE))), new CommitId(trim($this->git($directory, 'rev-parse', 'HEAD'))));
        $panel = $this->panels->panelForChange($project, $this->describe($diff));
        // A name of its own per run, because rounds of one change share a finding ledger and a second run must not see the first run's findings.
        $round = new RequestedRound('bench/'.$name.'-'.bin2hex(random_bytes(4)));
        $request = new ChangeReviewRequest($project, $directory, $task, $diff, $round, new VerdictIdentity($range, ReviewTerms::of($task, self::CHECK_COMMAND, $panel)), $panel);

        return new BenchChange($name, Expectations::fromJson($this->filesystem->readFile($folder.'/expectations.json')), $this->filesOf($folder.'/tree'), $request);
    }

    private function build(string $directory, string $name, string $tree): string
    {
        $this->filesystem->mkdir($directory);
        // The git folder is named, because git init refuses the pinned work tree without one.
        $call = $this->hardened->call($directory, '--git-dir=.git', 'init', '--quiet', '--initial-branch='.self::BASE);
        new Process([...$call->command], $call->directory, [...$call->environment], timeout: self::TIMEOUT_SECONDS)->mustRun();
        $this->commit($directory, $this->fixtures.'/base', 'Add the shop helpers');
        $this->git($directory, 'switch', '--quiet', '--create', 'bench/'.$name);
        $this->commit($directory, $tree, 'Change '.$name);

        return $this->git($directory, 'diff', '--no-color', '--no-ext-diff', '--no-textconv', '--text', '--no-renames', self::BASE.'..HEAD');
    }

    private function commit(string $directory, string $source, string $message): void
    {
        $this->filesystem->mirror($source, $directory, options: ['override' => true]);
        $this->git($directory, 'add', '--all');
        $this->git($directory, 'commit', '--quiet', '--no-gpg-sign', '--message', $message);
    }

    private function git(string $directory, string ...$arguments): string
    {
        $call = $this->hardened->call($directory, ...$arguments);

        return new Process([...$call->command], $call->directory, [...$call->environment, ...self::COMMITTER], timeout: self::TIMEOUT_SECONDS)->mustRun()->getOutput();
    }

    private function describe(string $diff): ChangeDescription
    {
        $lines = explode("\n", $diff);
        $added = array_filter($lines, static fn (string $line): bool => str_starts_with($line, '+') && !str_starts_with($line, '+++'));
        $paths = array_map(static fn (string $line): string => substr($line, strlen('+++ b/')), array_filter($lines, static fn (string $line): bool => str_starts_with($line, '+++ b/')));

        return new ChangeDescription(count($added), new Paths(...array_values($paths)), new Labels());
    }

    // The base and the change together, because an expectation names a line of the file as the change leaves it.
    private function filesOf(string $tree): ChangeFiles
    {
        $lineCounts = [];
        foreach ([$this->fixtures.'/base', $tree] as $directory) {
            foreach (new Finder()->files()->ignoreDotFiles(false)->ignoreVCS(false)->in($directory) as $file) {
                $lineCounts[$file->getRelativePathname()] = substr_count(rtrim($file->getContents(), "\n"), "\n") + 1;
            }
        }

        return new ChangeFiles($lineCounts);
    }
}
