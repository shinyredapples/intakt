<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;

// Through GraphQL, because the REST API names neither the last editor of a text nor who applied a label.
final readonly class GitHubIssueHistories
{
    // Paged back from the newest label events, because a label still on the item may have been applied long before the last hundred events.
    private const string FIELDS = 'author { __typename login } editor { __typename login } lastEditedAt timelineItems(last: 100, before: $before, itemTypes: [LABELED_EVENT]) { pageInfo { hasPreviousPage startCursor } nodes { ... on LabeledEvent { actor { __typename login } label { name } } } }';
    private const string QUERY = 'query($owner: String!, $name: String!, $number: Int!, $before: String) { repository(owner: $owner, name: $name) { issueOrPullRequest(number: $number) { ... on Issue { '.self::FIELDS.' } ... on PullRequest { '.self::FIELDS.' } } } }';
    // A longer history fails, because an application left unread could be the one a label counts by.
    private const int MAX_PAGES = 10;

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
        $editor = null;
        $events = [];
        $before = null;
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $item = $this->itemOf(['owner' => $owner, 'name' => $name, 'number' => $number, 'before' => $before]);
            $editor ??= $this->editorOf($item);
            $events = [...$this->labelEventsOf($item), ...$events];
            $before = $this->earlierCursorOf($item);
            if (null === $before) {
                return new IssueHistory($editor, $this->labelAppliersOf($events));
            }
        }

        throw new ForgeReadFailed('GitHub answered a label history longer than this forge reads.');
    }

    /**
     * @param array<string, int|string|null> $variables
     *
     * @return array<mixed>
     */
    private function itemOf(array $variables): array
    {
        $body = json_encode(['query' => self::QUERY, 'variables' => $variables], JSON_THROW_ON_ERROR);
        $answer = $this->reads->decode($this->reads->send('POST', '/graphql', ['body' => $body])['body']);

        // GitHub answers a field it could not resolve with null beside an error, and a null editor would then pass for the author.
        if ([] !== ($answer['errors'] ?? [])) {
            throw new ForgeReadFailed('GitHub answered the history of an item with errors.');
        }

        $item = $this->at($answer, 'data', 'repository', 'issueOrPullRequest');
        if (!is_array($item) || [] === $item) {
            throw new ForgeReadFailed('GitHub answered without the issue.');
        }

        return $item;
    }

    /**
     * @param array<mixed> $item
     */
    private function editorOf(array $item): string
    {
        $editor = $this->at($item, 'editor');
        if (null !== $editor) {
            return $this->loginOf($editor) ?? throw new ForgeReadFailed('GitHub answered an editor without a login.');
        }

        // GitHub answers no editor both for a text nobody edited and for an editor it cannot name, so only an unedited text is the author's.
        if (null !== $this->at($item, 'lastEditedAt')) {
            throw new ForgeReadFailed('GitHub answered an edited issue without its editor.');
        }

        // Refused without a login, because a text nobody can name is not trusted and a deleted account has none.
        return $this->loginOf($this->at($item, 'author')) ?? throw new ForgeReadFailed('GitHub answered an issue without an editor.');
    }

    /**
     * @param array<mixed> $item
     *
     * @return list<mixed>
     */
    private function labelEventsOf(array $item): array
    {
        $events = $this->at($item, 'timelineItems', 'nodes');
        if (!is_array($events) || !array_is_list($events)) {
            throw new ForgeReadFailed('GitHub answered without the label history.');
        }

        return $events;
    }

    /**
     * @param array<mixed> $item
     */
    private function earlierCursorOf(array $item): ?string
    {
        $earlier = $this->at($item, 'timelineItems', 'pageInfo', 'hasPreviousPage');
        $cursor = $this->at($item, 'timelineItems', 'pageInfo', 'startCursor');
        if (false === $earlier) {
            return null;
        }

        return true === $earlier && is_string($cursor) && '' !== $cursor ? $cursor : throw new ForgeReadFailed('GitHub answered a label history without its page info.');
    }

    /**
     * @param list<mixed> $events
     *
     * @return array<string, ?string>
     */
    private function labelAppliersOf(array $events): array
    {
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

        // Suffixed for an app, because GraphQL may name it without the `[bot]` the permission check knows it by; a login that has it keeps one.
        return 'Bot' === $this->at($account, '__typename') && !str_ends_with($login, '[bot]') ? $login.'[bot]' : $login;
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
