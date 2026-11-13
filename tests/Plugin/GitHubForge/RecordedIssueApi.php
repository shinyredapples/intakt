<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\BudgetReserve;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueHistories;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueReads;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use LogicException;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

// GitHub's issue endpoints as scripted pages (a body, or three digits for an HTTP status) with every request kept, because a test must see which pages a listing read; a page names a next one only when listed in `namingNext`.
final class RecordedIssueApi
{
    /** @var list<string> */
    public array $requests = [];
    /** @var array<int, string> */
    public array $pages = [];
    public string $etag = 'W/"issues-1"';
    public bool $notModified = false;
    /** @var list<int> */
    public array $namingNext = [];
    /** @var list<string> */
    public array $conditions = [];
    // A body, or three digits for an HTTP status, because the history read is scripted like the listing pages.
    public string $graphql = '{}';
    /** @var list<string> */
    public array $graphqlRequests = [];
    /** @var array<string, string> */
    public array $graphqlHeaders = [];
    private BudgetReserve $reserve;

    public function reads(): GitHubIssueReads
    {
        [$calls, $checked] = $this->wiring();

        return new GitHubIssueReads($calls, $checked, new WatchedRepositories(['alpha' => ['github' => 'acme/widgets'], 'beta' => []]), new GitHubMoments(), $this->reserve);
    }

    public function histories(): GitHubIssueHistories
    {
        [$calls, $checked] = $this->wiring();

        return new GitHubIssueHistories($calls, $checked, new WatchedRepositories(['alpha' => ['github' => 'acme/widgets'], 'beta' => []]));
    }

    /**
     * @return array{GitHubCalls, CheckedInstallationTokens}
     */
    private function wiring(): array
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $http = new MockHttpClient($this->answer(...), 'https://api.github.com');
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());

        $this->reserve = FreshReadings::reserveOf($readings, $tokens, $clock);

        return [new GitHubCalls($http, $readings, $tokens), new CheckedInstallationTokens($readBack, $tokens)];
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
    private function answerHistory(array $options): ResponseInterface
    {
        $this->graphqlRequests[] = is_string($options['body'] ?? null) ? $options['body'] : '';

        return 1 === preg_match('/^\d{3}$/', $this->graphql) ? new MockResponse('{}', ['http_code' => (int) $this->graphql]) : new MockResponse($this->graphql, ['response_headers' => $this->graphqlHeaders]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $this->requests[] = $method.' '.$url;
        $this->keepConditions($options);

        if (str_ends_with($url, '/graphql')) {
            return $this->answerHistory($options);
        }

        if ($this->notModified) {
            return new MockResponse('', ['http_code' => 304]);
        }

        $page = 1 === preg_match('/[?&]page=(\d+)/', $url, $found) ? (int) $found[1] : 1;
        $body = $this->pages[$page] ?? '[]';

        if (1 === preg_match('/^\d{3}$/', $body)) {
            return new MockResponse('{}', ['http_code' => (int) $body, 'response_headers' => ['Retry-After' => '60']]);
        }

        $headers = ['ETag' => $this->etag];
        if (in_array($page, $this->namingNext, true)) {
            $headers['Link'] = sprintf('<https://api.github.com/repositories/1/issues?page=%d>; rel="next", <https://api.github.com/repositories/1/issues?page=9>; rel="last"', $page + 1);
        }

        return new MockResponse($body, ['response_headers' => $headers]);
    }
}
