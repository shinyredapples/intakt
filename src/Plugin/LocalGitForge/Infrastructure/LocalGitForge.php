<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\FileContent;
use Intakt\Plugin\Forge\Contract\FileNotFoundAtHead;
use Intakt\Plugin\Forge\Contract\Forge;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Symfony\Component\Process\Process;

final readonly class LocalGitForge implements Forge
{
    private const int TIMEOUT_SECONDS = 60;

    public function __construct(
        private LocalRepository $repository,
        private NameStatusParser $nameStatus,
    ) {
    }

    public function change(ChangeId $id): Change
    {
        $head = $this->headOf($id);
        $base = $this->mergeBase($head);

        return new Change(
            $id,
            $base,
            $head,
            $this->read(['diff', '--no-color', '--no-ext-diff', '--no-textconv', '--no-renames', $base->hash, $head->hash]),
            $this->nameStatus->parse($this->read(['diff', '--name-status', '-z', '--no-renames', $base->hash, $head->hash])),
        );
    }

    public function hasHeadMoved(Change $change): bool
    {
        return !$this->headOf($change->id)->equals($change->head);
    }

    public function fileAtHead(Change $change, string $path): FileContent
    {
        // The head hash, not the branch name, so a branch that moved on cannot change what is read.
        $process = $this->git(['cat-file', 'blob', $change->head->hash.':'.$path]);

        if (!$process->isSuccessful()) {
            throw FileNotFoundAtHead::at($path, $change->head);
        }

        return new FileContent($path, $change->head, $process->getOutput());
    }

    private function headOf(ChangeId $id): CommitId
    {
        // show-ref takes only an exact ref, so revision expressions such as "feature~1" are not branches.
        $process = $this->git(['show-ref', '--verify', '--hash', 'refs/heads/'.$id->value]);

        if (!$process->isSuccessful()) {
            throw ChangeNotFound::named($id);
        }

        return new CommitId(trim($process->getOutput()));
    }

    private function mergeBase(CommitId $head): CommitId
    {
        $base = $this->read(['merge-base', 'refs/heads/'.$this->repository->baseBranch, $head->hash]);

        return new CommitId(trim($base));
    }

    /**
     * @param list<string> $arguments
     */
    private function read(array $arguments): string
    {
        $process = $this->git($arguments);

        if (!$process->isSuccessful()) {
            throw new ForgeReadFailed(sprintf('git %s failed: %s', $arguments[0], trim($process->getErrorOutput())));
        }

        return $process->getOutput();
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments): Process
    {
        $process = new Process(['git', ...$arguments], $this->repository->path, timeout: self::TIMEOUT_SECONDS);
        $process->run();

        return $process;
    }
}
