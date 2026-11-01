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
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use InvalidArgumentException;
use JsonException;
use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubForge implements Forge
{
    private const string JSON = 'application/vnd.github+json';
    private const string DIFF = 'application/vnd.github.diff';
    private const string RAW = 'application/vnd.github.raw+json';
    private const int PAGE_SIZE = 100;
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
        $base = $this->mergeBase($address, $this->textAt($pull, 'base', 'ref'), $head);
        $commits = new CommitRange($base, $head);

        return new Change($id, $commits, $this->diff($address, $commits));
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

        return new HeadMove(HeadMoveKind::Commits, $this->pathsOf($comparison));
    }

    public function fileAtHead(Change $change, string $path): FileContent
    {
        $head = $change->commits->head;
        $address = PullRequestAddress::of($change->id);
        $segments = array_map(rawurlencode(...), explode('/', $path));
        $content = $this->get($address->repositoryPath().'/contents/'.implode('/', $segments), self::RAW, ['ref' => $head->hash]);

        return null === $content ? throw FileNotFoundAtHead::at($path, $head) : new FileContent($path, $head, $content);
    }

    /**
     * @return array<mixed>
     */
    private function pullRequest(PullRequestAddress $address, ChangeId $id): array
    {
        $body = $this->get($address->pullRequestPath(), self::JSON);

        return null === $body ? throw ChangeNotFound::named($id) : $this->decode($body);
    }

    private function mergeBase(PullRequestAddress $address, string $baseReference, CommitId $head): CommitId
    {
        $comparison = $this->compare($address, $this->encodeReference($baseReference), $head->hash);

        return null === $comparison ? throw new ForgeReadFailed('GitHub has no comparison of the base branch with the head.') : $this->commitAt($comparison, 'merge_base_commit', 'sha');
    }

    private function diff(PullRequestAddress $address, CommitRange $commits): Diff
    {
        // Read between the two commits, not from the pull request, so a head that moves meanwhile cannot mix two states.
        $patch = $this->get($address->repositoryPath().'/compare/'.$commits->base->hash.'...'.$commits->head->hash, self::DIFF);

        return new Diff($patch ?? throw new ForgeReadFailed('GitHub has no diff between the base and the head.'), $this->changedFiles($address));
    }

    private function changedFiles(PullRequestAddress $address): ChangedFiles
    {
        $files = [];
        for ($page = 1; ; ++$page) {
            $body = $this->get($address->pullRequestPath().'/files', self::JSON, ['per_page' => self::PAGE_SIZE, 'page' => $page]);
            $entries = $this->decode($body ?? throw new ForgeReadFailed('GitHub has no file list for the pull request.'));
            $files = [...$files, ...array_merge(...array_map($this->filesOf(...), $entries))];

            if (count($entries) < self::PAGE_SIZE) {
                return new ChangedFiles(...$files);
            }
        }
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

    /**
     * @param array<mixed> $comparison
     *
     * @return list<string>
     */
    private function pathsOf(array $comparison): array
    {
        $files = $comparison['files'] ?? [];
        if (!is_array($files)) {
            throw new ForgeReadFailed('GitHub answered a comparison without a list of files.');
        }

        return array_values(array_map(fn (mixed $file): string => $this->textAt(is_array($file) ? $file : [], 'filename'), $files));
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
            throw new ForgeReadFailed('GitHub answered something other than a commit hash.', previous: $invalid);
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
            throw new ForgeReadFailed('GitHub answered something other than JSON.', previous: $invalid);
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
            throw new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure::class), previous: $failure);
        }

        if (self::NOT_FOUND === $status) {
            return null;
        }

        return $status >= 200 && $status < 300 ? $body : throw new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status));
    }

    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted $notMinted) {
            throw new ForgeReadFailed('No GitHub installation token for a read.', previous: $notMinted);
        }
    }
}
