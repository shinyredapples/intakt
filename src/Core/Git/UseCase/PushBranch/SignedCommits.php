<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\UnsignedCommitRefused;
use Intakt\Core\Git\Domain\CommitObject;

// Each new commit keeps its tree, parents, author, committer, dates and message and gains the orchestrator's signature, and an SSH signature of this key is deterministic, so a retry after a crash produces the same ids.
final readonly class SignedCommits
{
    public function __construct(
        private BranchGit $git,
        private SigningKey $key,
    ) {
    }

    /**
     * @throws BranchNotPushed
     */
    public function of(string $repository, string $head, ?string $base, string $main): string
    {
        $header = $this->signatureHeader($repository);
        $replaced = [];

        foreach ($this->newCommits($repository, $head, $base, $main) as $commit) {
            $unsigned = CommitObject::of($this->git->output($repository, ['cat-file', 'commit', $commit]))->reparented($replaced)->withoutSignature();
            $signed = $unsigned->signedWith($this->key->sign($unsigned->text()), $header);
            $replaced[$commit] = trim($this->git->output($repository, ['hash-object', '-t', 'commit', '-w', '--stdin'], $signed->text()));
        }

        return $replaced[$head] ?? $head;
    }

    /**
     * @throws BranchNotPushed
     * @throws UnsignedCommitRefused
     */
    public function verify(string $repository, string $head, string $main): void
    {
        $header = $this->signatureHeader($repository);

        foreach ($this->newCommits($repository, $head, null, $main) as $commit) {
            $object = CommitObject::of($this->git->output($repository, ['cat-file', 'commit', $commit]));
            $signature = $object->signature($header);

            if (null === $signature) {
                throw UnsignedCommitRefused::commit($commit, 'it has no signature');
            }

            if (!$this->key->signed($object->payload(), $signature)) {
                throw UnsignedCommitRefused::commit($commit, 'its signature does not verify');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function newCommits(string $repository, string $head, ?string $base, string $main): array
    {
        $excluded = null === $base ? [$main] : [$base, $main];
        $listed = $this->git->lines($repository, ['rev-list', '--reverse', '--topo-order', $head, '--not', ...$excluded]);

        return '' === $listed ? [] : explode("\n", $listed);
    }

    private function signatureHeader(string $repository): string
    {
        return 'sha256' === $this->git->lines($repository, ['rev-parse', '--show-object-format']) ? 'gpgsig-sha256' : 'gpgsig';
    }
}
