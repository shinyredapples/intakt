<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueHistories;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueReads;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReads;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use Intakt\Tests\Plugin\GitHubForge\AppKey;
use Intakt\Tests\Plugin\GitHubForge\FreshReadings;
use Intakt\Tests\Plugin\GitHubForge\RecordedInstallation;
use LogicException;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

// GitHub's issue endpoints as scripted pages (a body, or three digits for an HTTP status) with every request kept, because a test must see which pages a listing read.
final class RecordedIssueApi
{
    /** @var list<string> */
    public array $requests = [];
    /** @var array<int, string> */
    public array $pages = [];
    public string $etag = 'W/"issues-1"';
    public bool $notModified = false;
    public string $historyAnswer = '{"data":{"repository":{"issueOrPullRequest":{"author":{"__typename":"User","login":"ana"},"editor":null,"timelineItems":{"nodes":[{"actor":{"__typename":"User","login":"ana"},"label":{"name":"intakt"}},{"actor":{"__typename":"User","login":"ana"},"label":{"name":"priority:urgent"}}]}}}}}';
    // Switchable, because GitHub names the next page in a Link header and a test must see what a listing does when the header is missing.
    public bool $links = true;
    /** @var list<string> */
    public array $conditions = [];

    public function reads(): GitHubIssueReads
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $http = new MockHttpClient($this->answer(...), 'https://api.github.com');
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());

        $reads = new InstallationReads(new GitHubCalls($http, $readings, $tokens), new CheckedInstallationTokens($readBack, $tokens));
        $repositories = new WatchedRepositories(['alpha' => ['github' => 'acme/widgets'], 'beta' => []]);

        return new GitHubIssueReads($reads, new GitHubIssueHistories($reads, $repositories), $repositories, new GitHubMoments(), FreshReadings::reserveOf($readings, $tokens, $clock));
    }

    public static function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__.'/Fixtures/'.$name);

        return false === $content ? throw new LogicException('Missing fixture '.$name) : $content;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function keepConditions(array $options): void
    {
        $headers = $options['headers'] ?? [];
        foreach (is_array($headers) ? $headers : [] as $header) {
            if (is_string($header) && str_starts_with($header, 'If-None-Match: ')) {
                $this->conditions[] = substr($header, strlen('If-None-Match: '));
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $this->requests[] = $method.' '.$url;
        $this->keepConditions($options);

        if ('POST' === $method) {
            return new MockResponse($this->historyAnswer);
        }

        if ($this->notModified) {
            return new MockResponse('', ['http_code' => 304]);
        }

        $page = 1 === preg_match('/[?&]page=(\d+)/', $url, $found) ? (int) $found[1] : 1;
        $body = $this->pages[$page] ?? '[]';

        $headers = ['ETag' => $this->etag];
        if ($this->links && isset($this->pages[$page + 1])) {
            $headers['Link'] = sprintf('<https://api.github.com/repositories/1/issues?state=open&per_page=100&page=%d>; rel="next"', $page + 1);
        }

        return 1 === preg_match('/^\d{3}$/', $body) ? new MockResponse('{}', ['http_code' => (int) $body]) : new MockResponse($body, ['response_headers' => $headers]);
    }
}
