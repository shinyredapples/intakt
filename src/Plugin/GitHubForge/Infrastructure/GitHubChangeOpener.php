<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ChangeOpening;
use Intakt\Plugin\Forge\Contract\ChangeTextHoldsSecret;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\OpenedChange;
use Intakt\Plugin\Forge\Contract\Opening;
use Intakt\Plugin\Forge\Contract\ProposedChange;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

final readonly class GitHubChangeOpener implements ChangeOpening
{
    private const string JSON = 'application/vnd.github+json';
    private const int UNAUTHORIZED = 401;
    private const int PAGE_SIZE = 100;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private SecretScan $secrets,
        private WatchedRepositories $repositories,
        private SteeringIdentities $identities,
    ) {
    }

    public function refuseForeignChange(ProposedChange $change): void
    {
        $this->ownPullRequestsOf($this->addressOf($change->project), $change->branch);
    }

    public function openOrUpdate(ProposedChange $change): OpenedChange
    {
        $text = $change->text;

        if ($this->secrets->findsSecretIn($text->title."\n\n".$text->body)) {
            throw ChangeTextHoldsSecret::inTheText();
        }

        $repository = $this->addressOf($change->project);
        // Asked again, because another account can open a pull request of the branch between the check before the push and this write.
        $open = $this->ownNumberToBase($this->ownPullRequestsOf($repository, $change->branch), $change->base);
        $payload = ['title' => $text->title, 'body' => $text->body];

        // The look-up comes first and a write is never retried, so a crash between the two leaves at most the pull request the next call finds.
        if (null === $open) {
            return new OpenedChange($repository->pullRequest($this->numberOf($this->send('POST', $repository->path().'/pulls', [...$payload, 'base' => $change->base, 'head' => $change->branch]))), Opening::Created);
        }

        // The update sends no base, because one sent would move back a pull request retargeted after the look-up.
        return new OpenedChange($repository->pullRequest($this->numberOf($this->send('PATCH', $repository->path().'/pulls/'.$open, $payload))), Opening::Created);
    }

    private function addressOf(string $project): RepositoryAddress
    {
        try {
            return $this->repositories->addressOf($project);
        } catch (ForgeReadFailed $unknown) {
            throw new ForgeWriteFailed($unknown->getMessage(), 0, $unknown);
        }
    }

    // Every match is checked, never only the first, because the branch can have open pull requests to several bases and a push moves the head of each; each `base` is read only by the pick, so every author is checked first.
    /**
     * @return array<int, mixed>
     */
    private function ownPullRequestsOf(RepositoryAddress $repository, string $branch): array
    {
        $own = [];

        foreach ($this->openPullRequestsOf($repository, $branch) as $entry) {
            $pull = is_array($entry) ? $entry : [];

            if ($this->isOfBranch($pull, $branch)) {
                $own[$this->ownNumberOf($pull)] = $pull['base'] ?? null;
            }
        }

        return $own;
    }

    // Only the own pull request to the change's base is updated, because sending the base to one opened to another base would retarget it.
    /**
     * @param array<int, mixed> $own
     */
    private function ownNumberToBase(array $own, string $base): ?int
    {
        return array_find_key($own, fn (mixed $listedBase): bool => $this->refOf($listedBase) === $base);
    }

    private function refOf(mixed $listedBase): string
    {
        $ref = is_array($listedBase) ? ($listedBase['ref'] ?? null) : null;

        return is_string($ref) && '' !== $ref ? $ref : throw new ForgeWriteFailed('GitHub listed this App\'s open pull request of the branch without its base, so the branch is left alone.');
    }

    // One page only, because offset pages read one after the other can shift and skip a pull request that stays open throughout; one answer is one listing, and only one that is not full shows it holds them all.
    /**
     * @return list<mixed>
     */
    private function openPullRequestsOf(RepositoryAddress $repository, string $branch): array
    {
        $query = ['state' => 'open', 'head' => $repository->headOf($branch), 'per_page' => self::PAGE_SIZE];
        $listed = $this->listOf($this->request('GET', $repository->path().'/pulls', ['query' => $query]));

        return count($listed) < self::PAGE_SIZE ? $listed : throw new ForgeWriteFailed(sprintf('GitHub listed %d open pull requests of the branch, a full page that may not be all of them, so the branch is left alone.', self::PAGE_SIZE));
    }

    /**
     * @return list<mixed>
     */
    private function listOf(string $body): array
    {
        $listed = $this->decoded($body);

        // `{}` decodes to the same empty array as `[]`, so only the answer's first character tells an empty object from an empty list.
        return array_is_list($listed) && str_starts_with(ltrim($body), '[') ? $listed : throw new ForgeWriteFailed('GitHub answered the pull request look-up with something other than a list.');
    }

    // A listed entry whose branch cannot be read, or is empty, is refused, because the listing is of this branch and the entry may be another account's pull request of it.
    /**
     * @param array<mixed> $pull
     */
    private function isOfBranch(array $pull, string $branch): bool
    {
        $ref = is_array($pull['head'] ?? null) ? ($pull['head']['ref'] ?? null) : null;

        return is_string($ref) && '' !== $ref ? $ref === $branch : throw new ForgeWriteFailed('GitHub listed an open pull request without its branch, so the branch is left alone.');
    }

    /**
     * @param array<mixed> $pull
     */
    private function ownNumberOf(array $pull): int
    {
        // GitHub alone sets the author, so only this App's own bot account marks a pull request as Intakt's own; a retry after a push that stopped before its record finds it open.
        if (!$this->wasCreatedByThisApp($pull)) {
            throw new ForgeWriteFailed('The branch has an open pull request that another account opened, so Intakt leaves it alone and neither pushes to the branch nor writes to the pull request.');
        }

        return $this->numberOf($pull);
    }

    /**
     * @param array<mixed> $pull
     */
    private function wasCreatedByThisApp(array $pull): bool
    {
        $author = $pull['user'] ?? null;
        $login = is_array($author) ? ($author['login'] ?? null) : null;

        // Refused, because an author GitHub did not name may be another account.
        if (!is_string($login) || '' === $login) {
            throw new ForgeWriteFailed('GitHub answered an open pull request of the branch without its author\'s login, so the branch is left alone before its author can be checked against this App.');
        }

        if (!$this->identities->isBot($login)) {
            return false;
        }

        try {
            return $this->identities->isOwn($login);
        } catch (TrustNotChecked $unchecked) {
            throw new ForgeWriteFailed('The author of an open pull request of the branch could not be checked against this App: '.$unchecked->getMessage(), 0, $unchecked);
        }
    }

    /**
     * @param array<mixed> $pull
     */
    private function numberOf(array $pull): int
    {
        $number = $pull['number'] ?? null;

        return is_int($number) && $number > 0 ? $number : throw new ForgeWriteFailed('GitHub answered a pull request without a number.');
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    private function send(string $method, string $path, array $payload): array
    {
        return $this->decoded($this->request($method, $path, ['json' => $payload, 'max_retries' => 0]));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $path, array $options): string
    {
        $options += ['auth_bearer' => $this->bearer(), 'headers' => ['Accept' => self::JSON]];

        try {
            $response = $this->calls->asInstallation($method, $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ForgeRateLimited $limited) {
            // Chained, because the pause carries when the call may be tried again and nothing of the request.
            throw new ForgeWriteFailed('GitHub is rate limited for a pull request call: '.$limited->getMessage(), 0, $limited);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeWriteFailed(sprintf('GitHub answered %d to a pull request call.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return $body;
    }

    // Not chained, because the trace of a failed call holds the bearer token.
    private function unreachable(string $failure): ForgeWriteFailed
    {
        return new ForgeWriteFailed(sprintf('GitHub could not be reached for a pull request call (%s).', $failure));
    }

    /**
     * @return array<mixed>
     */
    private function decoded(string $body): array
    {
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeWriteFailed('GitHub answered a pull request call with something other than JSON.', 0, $invalid);
        }

        return is_array($data) ? $data : throw new ForgeWriteFailed('GitHub answered a pull request call with JSON that is not an object or a list.');
    }

    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted $notMinted) {
            throw new ForgeWriteFailed('No GitHub installation token for a write.', $notMinted->getCode(), previous: $notMinted);
        } catch (InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeWriteFailed('No GitHub write before the installation is confirmed: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }
}
