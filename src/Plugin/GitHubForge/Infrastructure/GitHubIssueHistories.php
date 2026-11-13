<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;

// Through GraphQL, because the REST API names neither the last editor of a text nor who applied a label.
final readonly class GitHubIssueHistories
{
    // The last hundred label events, because the label that counts is the one applied last; an older application is not trusted unseen.
    private const string FIELDS = 'author { __typename login } editor { __typename login } timelineItems(last: 100, itemTypes: [LABELED_EVENT]) { nodes { ... on LabeledEvent { actor { __typename login } label { name } } } }';
    private const string QUERY = 'query($owner: String!, $name: String!, $number: Int!) { repository(owner: $owner, name: $name) { issueOrPullRequest(number: $number) { ... on Issue { '.self::FIELDS.' } ... on PullRequest { '.self::FIELDS.' } } } }';

    public function __construct(
        private InstallationReads $reads,
        private WatchedRepositories $repositories,
    ) {
    }

    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function of(string $project, int $number): IssueHistory
    {
        [$owner, $name] = explode('/', $this->repositories->addressOf($project)->fullName(), 2);
        $body = json_encode(['query' => self::QUERY, 'variables' => ['owner' => $owner, 'name' => $name, 'number' => $number]], JSON_THROW_ON_ERROR);
        $answer = $this->reads->decode($this->reads->send('POST', '/graphql', ['body' => $body])['body']);

        // GitHub answers a field it could not resolve with null beside an error, and a null editor would then pass for the author.
        if ([] !== ($answer['errors'] ?? [])) {
            throw new ForgeReadFailed('GitHub answered the history of an item with errors.');
        }

        $item = $this->at($answer, 'data', 'repository', 'issueOrPullRequest');
        if (!is_array($item) || [] === $item) {
            throw new ForgeReadFailed('GitHub answered without the issue.');
        }

        // Refused without a login, because a text nobody can name is not trusted and a deleted account has none.
        $editor = $this->loginOf($this->at($item, 'editor')) ?? $this->loginOf($this->at($item, 'author'));

        return new IssueHistory($editor ?? throw new ForgeReadFailed('GitHub answered an issue without an editor.'), $this->labelAppliersOf($item));
    }

    /**
     * @param array<mixed> $item
     *
     * @return array<string, ?string>
     */
    private function labelAppliersOf(array $item): array
    {
        $events = $this->at($item, 'timelineItems', 'nodes');
        if (!is_array($events)) {
            throw new ForgeReadFailed('GitHub answered without the label history.');
        }

        $appliers = [];
        foreach ($events as $event) {
            [$label, $applier] = $this->labelEventOf($event);
            // Moved to the end, because the order of the list must be the order of the last applications.
            unset($appliers[$label]);
            $appliers[$label] = $applier;
        }

        return $appliers;
    }

    /**
     * @return array{string, ?string}
     */
    private function labelEventOf(mixed $event): array
    {
        $label = is_array($event) ? $this->at($event, 'label', 'name') : null;
        if (!is_array($event) || !is_string($label)) {
            throw new ForgeReadFailed('GitHub answered a label event this forge cannot read.');
        }

        return [$label, $this->loginOf($this->at($event, 'actor'))];
    }

    private function loginOf(mixed $account): ?string
    {
        if (!is_array($account)) {
            return null;
        }

        $login = $this->at($account, 'login');
        if (!is_string($login) || '' === $login) {
            return null;
        }

        // Suffixed for an app, because GraphQL names it without the `[bot]` the permission check knows it by.
        return 'Bot' === $this->at($account, '__typename') ? $login.'[bot]' : $login;
    }

    /**
     * @param array<mixed> $data
     */
    private function at(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }
}
