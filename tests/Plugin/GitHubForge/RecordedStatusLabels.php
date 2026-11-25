<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Closure;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubLabelWrites;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubStatusLabels;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubStatusReads;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

// The labels, label events and comments of one pull request as GitHub keeps them, so the real status adapter reads what its own writes left; any other path goes to the recorded identity answers.
final class RecordedStatusLabels
{
    private const string MOMENT = '2026-11-25T10:00:00Z';

    /** @var list<string> */
    public array $labels = [];

    /** @var list<string> */
    public array $comments = [];

    public int $labelReads = 0;

    public int $labelReadStatus = 200;

    // Run once while the next label read is answered, so a test can let another writer in between the read and the write.
    public ?Closure $whileReadingLabels = null;

    /** @var list<array<mixed>> */
    private array $events = [];

    public function __construct(
        private readonly string $issuePath,
    ) {
    }

    public function adapter(string $repository): GitHubStatusLabels
    {
        $identity = new RecordedTrust(TrustChecks::clock())->client();
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($identity): ResponseInterface {
            $path = (string) parse_url($url, PHP_URL_PATH);

            return str_starts_with($path, $this->issuePath.'/') ? $this->answer($method, $url, $options) : $identity->request($method, $url, $options);
        }, 'https://api.github.com');
        $wiring = TrustChecks::wiring($http);
        $repositories = new WatchedRepositories(['alpha' => ['github' => $repository]]);

        return new GitHubStatusLabels(new GitHubStatusReads($wiring->calls, $wiring->tokens), $repositories, $wiring->identities, new GitHubIssueComments($wiring->calls, $wiring->tokens, $repositories, '12345'), new GitHubLabelWrites($wiring->calls, $wiring->tokens));
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        $firstPage = str_contains((string) parse_url($url, PHP_URL_QUERY), 'page=1');

        return match (true) {
            'GET' === $method && str_ends_with($path, '/labels') => $this->labelPage($firstPage),
            'GET' === $method => new MockResponse(json_encode($firstPage ? $this->events : [], JSON_THROW_ON_ERROR)),
            str_ends_with($path, '/comments') => $this->comment($body),
            'DELETE' === $method => $this->removal(rawurldecode(basename($path))),
            default => $this->addition($body),
        };
    }

    private function labelPage(bool $firstPage): ResponseInterface
    {
        ++$this->labelReads;
        $meanwhile = $this->whileReadingLabels;
        $this->whileReadingLabels = null;
        $listed = json_encode(200 !== $this->labelReadStatus ? ['message' => 'unavailable'] : ($firstPage ? array_map(static fn (string $label): array => ['name' => $label], $this->labels) : []), JSON_THROW_ON_ERROR);
        if ($meanwhile instanceof Closure) {
            $meanwhile();
        }

        return new MockResponse($listed, ['http_code' => $this->labelReadStatus]);
    }

    private function comment(string $body): ResponseInterface
    {
        $payload = (array) json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $this->comments[] = is_string($payload['body'] ?? null) ? $payload['body'] : '';
        $stored = [...$payload, 'user' => ['login' => RecordedTrust::OWN_IDENTITY, 'type' => 'Bot'], 'created_at' => self::MOMENT, 'updated_at' => self::MOMENT, 'performed_via_github_app' => ['id' => 12345]];

        return new MockResponse(json_encode($stored, JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }

    private function removal(string $label): ResponseInterface
    {
        $this->labels = array_values(array_diff($this->labels, [$label]));
        $this->events[] = ['event' => 'unlabeled', 'label' => ['name' => $label], 'actor' => ['login' => RecordedTrust::OWN_IDENTITY], 'created_at' => self::MOMENT];

        return new MockResponse('[]');
    }

    private function addition(string $body): ResponseInterface
    {
        foreach ((array) ((array) json_decode($body, true, flags: JSON_THROW_ON_ERROR))['labels'] as $label) {
            $this->labels[] = is_string($label) ? $label : '';
            $this->events[] = ['event' => 'labeled', 'label' => ['name' => $label], 'actor' => ['login' => RecordedTrust::OWN_IDENTITY], 'created_at' => self::MOMENT];
        }

        return new MockResponse('[]');
    }
}
