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

// Only the events of status labels, because a status plan reads nothing else and other labels changing must not look like a change of the status.
final readonly class GitHubStatusEvents
{
    private const int PAGE_SIZE = 100;
    private const int MAX_PAGES = 10;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
    ) {
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
            throw new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure::class), $failure->getCode(), $failure);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read of the events.', $status));
        }

        try {
            $events = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed('GitHub listed the events as something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        if (!is_array($events) || !array_is_list($events)) {
            throw new ForgeReadFailed('GitHub answered an event listing that is not a list.');
        }

        return array_map(static fn (mixed $event): array => is_array($event) ? $event : throw new ForgeReadFailed('GitHub listed an event that is not an object.'), $events);
    }

    /**
     * @throws ForgeReadFailed
     */
    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted|InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeReadFailed('No GitHub read of the events: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
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
