<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Core\Git\Contract\BuildClones;
use Intakt\Core\Git\Contract\ProjectMirror;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\Watcher\Contract\ProjectRepositories;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;

// One run builds one task and stops at the first step that fails; it holds no queue, slot or state of its own, so the build lane can call it.
final readonly class BuildTask
{
    private const string BRANCH = 'intakt/issue-%d';

    public function __construct(
        private IssueReads $issues,
        private ProjectRepositories $repositories,
        private ProjectMirror $mirror,
        private BuildClones $clones,
        private Ledger $ledger,
        #[Autowire(param: BuildServices::CLONES_DIRECTORY)]
        private string $directory,
    ) {
    }

    /**
     * @throws TaskStepNotRecorded
     */
    public function run(string $project, int $number): TaskOutcome
    {
        $completed = [];

        foreach (TaskStep::cases() as $step) {
            $failure = $this->attempt($step, $project, $number);

            if (null !== $failure) {
                $this->record($step->failed(), $project, $number, ['reason' => $failure]);

                return TaskOutcome::stoppedAt($completed, $step, $failure);
            }

            $this->record($step->succeeded(), $project, $number, TaskStep::Clone === $step ? ['branch' => $this->branchOf($number)] : []);
            $completed[] = $step;
        }

        return TaskOutcome::cloned($completed, $this->cloneDirectory($project, $number));
    }

    private function attempt(TaskStep $step, string $project, int $number): ?string
    {
        try {
            match ($step) {
                TaskStep::Task => $this->readTask($project, $number),
                TaskStep::Mirror => $this->updateMirror($project),
                TaskStep::Clone => $this->makeClone($project, $number),
            };
        } catch (InvalidArgumentException|IOException|ReportableFailure|RepositoryAttributesRefused $failure) {
            return $failure->getMessage();
        }

        return null;
    }

    private function readTask(string $project, int $number): void
    {
        foreach ($this->issues->listOpen($project, null)->issues() as $issue) {
            if ($this->isTask($issue, $number)) {
                return;
            }
        }

        throw TaskNotListed::of($project, $number);
    }

    // Because GitHub lists pull requests with issues, and a pull request is no task.
    private function isTask(ListedIssue $issue, int $number): bool
    {
        return $issue->number === $number && !$issue->isPullRequest;
    }

    private function updateMirror(string $project): void
    {
        $this->mirror->update($project, $this->repositories->of($project));
    }

    private function makeClone(string $project, int $number): void
    {
        $this->clones->make($project, $this->branchOf($number), $this->cloneDirectory($project, $number));
    }

    private function branchOf(int $number): string
    {
        return sprintf(self::BRANCH, $number);
    }

    private function cloneDirectory(string $project, int $number): string
    {
        return sprintf('%s/%s-%d', $this->directory, $project, $number);
    }

    /**
     * @param array<string, string> $details
     */
    private function record(LedgerEventKind $kind, string $project, int $number, array $details): void
    {
        $payload = json_encode(['project' => $project, 'task' => $number, ...$details], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $result = $this->ledger->append(new LedgerEvent($kind, $payload));

        if (!$result->succeeded()) {
            throw TaskStepNotRecorded::of($project, $number, (string) $result->failure);
        }
    }
}
