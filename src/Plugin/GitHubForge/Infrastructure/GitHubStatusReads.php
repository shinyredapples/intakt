<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Domain\LabelEvent;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// The labels on a change and only the events of status labels, because the status reconciliation reads nothing else.
final readonly class GitHubStatusReads
{
    private const int PAGE_SIZE = 100;
    private const int MAX_PAGES = 10;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
    ) {
    }

    /**
     * @return list<string>
     *
     * @throws ForgeReadFailed
     */
    public function labelsOn(PullRequestAddress $address): array
    {
        $labels = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $listed = $this->page($address->issuePath().'/labels', $page);
            array_push($labels, ...array_map($this->labelName(...), $listed));
            if (count($listed) < self::PAGE_SIZE) {
                return $labels;
            }
        }

        throw new ForgeReadFailed('The change holds more labels than this forge reads.');
    }

    /**
     * @return list<LabelEvent>
     *
     * @throws ForgeReadFailed
     */
    public function of(PullRequestAddress $address): array
    {
        $events = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $listed = $this->page($address->issuePath().'/events', $page);
            foreach ($listed as $event) {
                $read = $this->labelEvent($event);
                if ($read instanceof LabelEvent) {
                    $events[] = $read;
                }
            }
            if (count($listed) < self::PAGE_SIZE) {
                return $events;
            }
        }

        throw new ForgeReadFailed('The change holds more events than this forge reads.');
    }

    /**
     * @return list<array<mixed>>
     *
     * @throws ForgeReadFailed
     */
    private function page(string $path, int $page): array
    {
        $options = ['auth_bearer' => $this->bearer(), 'query' => ['per_page' => self::PAGE_SIZE, 'page' => $page], 'headers' => ['Accept' => 'application/vnd.github+json']];

        try {
            $response = $this->calls->asInstallation('GET', $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read of %s.', $status, $path));
        }

        try {
            $entries = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed(sprintf('GitHub listed %s as something other than JSON.', $path), $invalid->getCode(), previous: $invalid);
        }

        if (!is_array($entries) || !array_is_list($entries)) {
            throw new ForgeReadFailed(sprintf('GitHub answered a listing of %s that is not a list.', $path));
        }

        return array_map(static fn (mixed $entry): array => is_array($entry) ? $entry : throw new ForgeReadFailed(sprintf('GitHub listed an entry of %s that is not an object.', $path)), $entries);
    }

    /**
     * @throws ForgeReadFailed
     */
    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted|InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeReadFailed('No GitHub read of the status labels: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unreachable(string $failure): ForgeReadFailed
    {
        return new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure));
    }

    /**
     * @param array<mixed> $label
     *
     * @throws ForgeReadFailed
     */
    private function labelName(array $label): string
    {
        $name = $label['name'] ?? null;

        return is_string($name) && '' !== $name ? $name : throw new ForgeReadFailed('GitHub listed a label without its name.');
    }

    /**
     * @param array<mixed> $event
     *
     * @throws ForgeReadFailed
     */
    private function labelEvent(array $event): ?LabelEvent
    {
        $kind = $event['event'] ?? null;
        if ('labeled' !== $kind && 'unlabeled' !== $kind) {
            return null;
        }

        $label = $event['label'] ?? null;
        $actor = $event['actor'] ?? null;
        $name = is_array($label) ? ($label['name'] ?? null) : null;
        $login = is_array($actor) ? ($actor['login'] ?? null) : null;
        if (!is_string($name) || '' === $name || !is_string($login) || '' === $login) {
            throw new ForgeReadFailed('GitHub listed a label event without its label or actor.');
        }

        return str_starts_with($name, RoundStatusLabel::FAMILY_PREFIX) ? new LabelEvent($name, $login, 'labeled' === $kind) : null;
    }
}
