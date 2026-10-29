<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\FileContent;
use Intakt\Plugin\Forge\Contract\FileNotFoundAtHead;
use Intakt\Plugin\Forge\Contract\Forge;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use InvalidArgumentException;
use NoDiscard;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

final readonly class LocalGitForge implements Forge
{
    private const int TIMEOUT_SECONDS = 60;
    private const int REFERENCE_MISSING = 2;
    private const int REFERENCE_INVALID = 1;

    public function __construct(
        private LocalRepository $repository,
        private NameStatusParser $nameStatus,
        private HardenedGit $hardened = new HardenedGit(),
    ) {
    }

    public function change(ChangeId $id): Change
    {
        $head = $this->headOf($id);
        $commits = new CommitRange($this->mergeBase($head), $head);

        return new Change($id, $commits, $this->diff($commits));
    }

    #[NoDiscard]
    public function hasHeadMoved(Change $change): bool
    {
        return !$this->headOf($change->id)->equals($change->commits->head);
    }

    public function fileAtHead(Change $change, string $path): FileContent
    {
        $head = $change->commits->head;

        return new FileContent($path, $head, $this->read(['cat-file', 'blob', $this->blobAt($head, $path)]));
    }

    private function headOf(ChangeId $id): CommitId
    {
        // An exact ref only, so tags and revision expressions such as "feature~1" are not branches.
        $reference = 'refs/heads/'.$id->value;

        // No branch can carry an invalid name; --branch is not used because it expands shorthands such as "@{-1}".
        $format = $this->git(['check-ref-format', $reference]);
        if (self::REFERENCE_INVALID === $format->getExitCode()) {
            throw ChangeNotFound::named($id);
        }

        if (!$format->isSuccessful()) {
            throw new ForgeReadFailed(sprintf('git check-ref-format failed: %s', trim($format->getErrorOutput())));
        }

        if (self::REFERENCE_MISSING === $this->git(['show-ref', '--exists', $reference])->getExitCode()) {
            throw ChangeNotFound::named($id);
        }

        return $this->commitFrom($this->read(['show-ref', '--verify', '--hash', $reference]));
    }

    private function mergeBase(CommitId $head): CommitId
    {
        return $this->commitFrom($this->read(['merge-base', 'refs/heads/'.$this->repository->baseBranch, $head->hash]));
    }

    private function diff(CommitRange $commits): Diff
    {
        // Every file is text, because a binary attribute would hide a change as "Binary files differ"; submodules are never ignored, because diff.ignoreSubmodules would hide a changed submodule commit.
        $options = ['--no-color', '--no-ext-diff', '--no-textconv', '--text', '--no-renames', '--ignore-submodules=none'];
        $range = [$commits->base->hash, $commits->head->hash];

        return new Diff(
            $this->read(['diff', ...$options, ...$range]),
            $this->nameStatus->parse($this->read(['diff', '--name-status', '-z', ...$options, ...$range])),
        );
    }

    private function blobAt(CommitId $head, string $path): string
    {
        // ls-tree lists nothing for a missing path but fails when the head cannot be read, which keeps the two apart.
        $entries = explode("\0", $this->read(['ls-tree', '-z', '--full-tree', $head->hash, '--', $path]));

        foreach ($entries as $entry) {
            if (1 === preg_match('/^\d{6} blob ([0-9a-f]{40}|[0-9a-f]{64})\t(.*)$/s', $entry, $match) && $match[2] === $path) {
                return $match[1];
            }
        }

        throw FileNotFoundAtHead::at($path, $head);
    }

    private function commitFrom(string $output): CommitId
    {
        try {
            return new CommitId(trim($output));
        } catch (InvalidArgumentException $invalid) {
            throw new ForgeReadFailed('git printed something other than a commit hash.', $invalid->getCode(), previous: $invalid);
        }
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
        // An argument with a NUL byte cannot reach git, because PHP refuses to start the process with it.
        if ([] !== array_filter($arguments, static fn (string $argument): bool => str_contains($argument, "\0"))) {
            throw new ForgeReadFailed(sprintf('git %s was given an argument with a NUL byte.', $arguments[0]));
        }

        try {
            $process = new Process($this->hardened->command($arguments), $this->repository->path, $this->hardened->environment($this->repository->path), timeout: self::TIMEOUT_SECONDS);
            $process->run();
        } catch (RepositoryAttributesRefused $refused) {
            throw new ForgeReadFailed($refused->getMessage(), $refused->getCode(), previous: $refused);
        } catch (ExceptionInterface $failure) {
            throw new ForgeReadFailed(sprintf('git %s did not run: %s', $arguments[0], $failure->getMessage()), $failure->getCode(), previous: $failure);
        }

        return $process;
    }
}
