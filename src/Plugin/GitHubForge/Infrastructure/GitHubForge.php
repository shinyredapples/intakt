<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\FileContent;
use Intakt\Plugin\Forge\Contract\FileNotFoundAtHead;
use Intakt\Plugin\Forge\Contract\FileStatus;
use Intakt\Plugin\Forge\Contract\Forge;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\ForgeWrites;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Plugin\Forge\Contract\ReviewComment;
use Intakt\Plugin\Forge\Contract\RoundStale;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use InvalidArgumentException;
use JsonException;
use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubForge implements Forge, ForgeWrites
{
    // An HTML comment, so the token is invisible on the pull request while the orchestrator can still match it.
    private const string TOKEN_MARKER = '<!-- intakt-review-token: %s -->';
    private const string JSON = 'application/vnd.github+json';
    private const string DIFF = 'application/vnd.github.diff';
    private const string RAW = 'application/vnd.github.raw+json';
    private const int COMPARED_FILES_CAP = 300;
    private const int NOT_FOUND = 404;

    public function __construct(
        #[Target('github.client')]
        private HttpClientInterface $http,
        private InstallationTokens $tokens,
    ) {
    }

    public function change(ChangeId $id): Change
    {
        $address = PullRequestAddress::of($id);
        $pull = $this->pullRequest($address, $id);
        $head = $this->commitAt($pull, 'head', 'sha');
        $comparison = $this->branchComparison($address, $this->textAt($pull, 'base', 'ref'), $head);
        $commits = new CommitRange($this->commitAt($comparison, 'merge_base_commit', 'sha'), $head);

        return new Change($id, $commits, new Diff($this->patch($address, $commits), $this->changedFiles($comparison)));
    }

    #[NoDiscard]
    public function hasHeadMoved(Change $change): bool
    {
        $address = PullRequestAddress::of($change->id);
        $current = $this->commitAt($this->pullRequest($address, $change->id), 'head', 'sha');

        return !$current->equals($change->commits->head);
    }

    public function headMoveSince(CommitId $earlier, Change $change): HeadMove
    {
        $head = $change->commits->head;
        if ($earlier->equals($head)) {
            return new HeadMove(HeadMoveKind::Unmoved, []);
        }

        $address = PullRequestAddress::of($change->id);
        $comparison = $this->compare($address, $earlier->hash, $head->hash);

        // A clean merge of the base is never reported, because the API cannot prove that the merge added nothing of its own.
        if (null === $comparison || 'ahead' !== ($comparison['status'] ?? null)) {
            return new HeadMove(HeadMoveKind::Rewritten, []);
        }

        $entries = $this->completeFilesOf($comparison);

        // An incomplete list cannot prove which paths the new commits touched, so the verdict must not survive on it.
        return null === $entries
            ? new HeadMove(HeadMoveKind::Rewritten, [])
            : new HeadMove(HeadMoveKind::Commits, array_map(static fn (ChangedFile $file): string => $file->path, $this->filesIn($entries)));
    }

    public function fileAtHead(Change $change, string $path): FileContent
    {
        $head = $change->commits->head;
        $address = PullRequestAddress::of($change->id);
        $segments = array_map(rawurlencode(...), explode('/', $path));
        $content = $this->get($address->repositoryPath().'/contents/'.implode('/', $segments), self::RAW, ['ref' => $head->hash]);

        return null === $content ? throw FileNotFoundAtHead::at($path, $head) : new FileContent($path, $head, $content);
    }

    public function postReview(string $project, Change $change, ReviewComment $comment): void
    {
        if ($this->hasHeadMoved($change)) {
            throw RoundStale::on($change);
        }

        $this->post(PullRequestAddress::of($change->id)->pullRequestPath().'/reviews', [
            // Pinned to the reviewed head, so a push between the check and the post cannot attach the review to a head it did not see.
            'commit_id' => $change->commits->head->hash,
            'event' => 'COMMENT',
            'body' => $comment->body."\n\n".sprintf(self::TOKEN_MARKER, $comment->token->value),
        ]);
    }

    /**
     * @return array<mixed>
     */
    private function pullRequest(PullRequestAddress $address, ChangeId $id): array
    {
        $body = $this->get($address->pullRequestPath(), self::JSON);

        return null === $body ? throw ChangeNotFound::named($id) : $this->decode($body);
    }

    /**
     * @return array<mixed>
     */
    private function branchComparison(PullRequestAddress $address, string $baseReference, CommitId $head): array
    {
        return $this->compare($address, $this->encodeReference($baseReference), $head->hash) ?? throw new ForgeReadFailed('GitHub has no comparison of the base branch with the head.');
    }

    private function patch(PullRequestAddress $address, CommitRange $commits): string
    {
        // Read between the two commits, not from the pull request, so a head that moves meanwhile cannot mix two states.
        $patch = $this->get($address->repositoryPath().'/compare/'.$commits->base->hash.'...'.$commits->head->hash, self::DIFF);

        return $patch ?? throw new ForgeReadFailed('GitHub has no diff between the base and the head.');
    }

    /**
     * @param array<mixed> $comparison
     */
    private function changedFiles(array $comparison): ChangedFiles
    {
        // The files of the comparison that named the merge base, so they belong to the same head as the patch.
        $entries = $this->completeFilesOf($comparison) ?? throw new ForgeReadFailed('GitHub listed the changed files incompletely (300 files or more, or no file list).');

        return new ChangedFiles(...$this->filesIn($entries));
    }

    /**
     * @param array<mixed> $comparison
     *
     * @return ?array<mixed>
     */
    private function completeFilesOf(array $comparison): ?array
    {
        $entries = $comparison['files'] ?? null;
        if (null !== $entries && !is_array($entries)) {
            throw new ForgeReadFailed('GitHub answered a comparison without a list of files.');
        }

        // GitHub lists at most 300 files of a comparison, so a list that long may be cut off.
        return null === $entries || count($entries) >= self::COMPARED_FILES_CAP ? null : $entries;
    }

    /**
     * @param array<mixed> $entries
     *
     * @return list<ChangedFile>
     */
    private function filesIn(array $entries): array
    {
        return array_merge([], ...array_map($this->filesOf(...), array_values($entries)));
    }

    /**
     * @return list<ChangedFile>
     */
    private function filesOf(mixed $entry): array
    {
        $entry = is_array($entry) ? $entry : throw new ForgeReadFailed('GitHub listed a changed file that is not an object.');
        $path = $this->textAt($entry, 'filename');

        // A rename is a deletion and an addition, as the local forge reports it, so a reviewer sees both paths.
        return match ($this->textAt($entry, 'status')) {
            'added', 'copied' => [new ChangedFile($path, FileStatus::Added)],
            'removed' => [new ChangedFile($path, FileStatus::Deleted)],
            'modified', 'changed' => [new ChangedFile($path, FileStatus::Modified)],
            'renamed' => [new ChangedFile($this->textAt($entry, 'previous_filename'), FileStatus::Deleted), new ChangedFile($path, FileStatus::Added)],
            default => throw new ForgeReadFailed('GitHub listed a changed file with a status this forge does not know.'),
        };
    }

    /**
     * @return ?array<mixed>
     */
    private function compare(PullRequestAddress $address, string $base, string $head): ?array
    {
        $body = $this->get($address->repositoryPath().'/compare/'.$base.'...'.$head, self::JSON);

        return null === $body ? null : $this->decode($body);
    }

    private function encodeReference(string $reference): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $reference)));
    }

    /**
     * @param array<mixed> $data
     */
    private function commitAt(array $data, string ...$keys): CommitId
    {
        try {
            return new CommitId($this->textAt($data, ...$keys));
        } catch (InvalidArgumentException $invalid) {
            throw new ForgeReadFailed('GitHub answered something other than a commit hash.', $invalid->getCode(), previous: $invalid);
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function textAt(array $data, string ...$keys): string
    {
        $value = $data;
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_string($value) && '' !== $value ? $value : throw new ForgeReadFailed(sprintf('GitHub answered without "%s".', implode('.', $keys)));
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed('GitHub answered something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        return is_array($data) ? $data : throw new ForgeReadFailed('GitHub answered JSON that is not an object or a list.');
    }

    /**
     * @param array<string, int|string> $query
     */
    private function get(string $path, string $accept, array $query = []): ?string
    {
        $options = ['auth_bearer' => $this->bearer(), 'query' => $query, 'headers' => ['Accept' => $accept]];

        try {
            $response = $this->http->request('GET', $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        if (self::NOT_FOUND === $status) {
            return null;
        }

        return $status >= 200 && $status < 300 ? $body : throw new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status));
    }

    /**
     * @param array<string, string> $payload
     */
    private function post(string $path, array $payload): void
    {
        $options = ['auth_bearer' => $this->writeBearer(), 'json' => $payload, 'headers' => ['Accept' => self::JSON]];

        try {
            $response = $this->http->request('POST', $path, $options);
            $status = $response->getStatusCode();
            $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachableForWrite($failure::class);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeWriteFailed(sprintf('GitHub answered %d to a write.', $status));
        }
    }

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unreachable(string $failure): ForgeReadFailed
    {
        return new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure));
    }

    // Not chained either, because the trace of a failed post holds the bearer token too.
    private function unreachableForWrite(string $failure): ForgeWriteFailed
    {
        return new ForgeWriteFailed(sprintf('GitHub could not be reached for a write (%s).', $failure));
    }

    private function writeBearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted $notMinted) {
            throw new ForgeWriteFailed('No GitHub installation token for a write.', $notMinted->getCode(), previous: $notMinted);
        }
    }

    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted $notMinted) {
            throw new ForgeReadFailed('No GitHub installation token for a read.', $notMinted->getCode(), previous: $notMinted);
        }
    }
}
