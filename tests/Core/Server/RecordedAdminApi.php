<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Infrastructure\AdminApi;
use Intakt\Core\Server\Infrastructure\AdminApiServerStatus;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Answers from responses recorded on FrankenPHP 1.13.1 with the Mercure hub, so no test needs a live server.
final class RecordedAdminApi
{
    /** @var array<string, array{body: string, status: int}> */
    private array $answers;

    private bool $reachable = true;

    /** @var list<array<string, mixed>> */
    public array $requestOptions = [];

    public function __construct()
    {
        $this->answers = [
            '/frankenphp/threads' => ['body' => self::fixture('threads.json'), 'status' => 200],
            '/metrics' => ['body' => self::fixture('metrics.txt'), 'status' => 200],
            '/mercure/health/ready' => ['body' => self::fixture('hub_ready.json'), 'status' => 200],
        ];
    }

    public static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name);
    }

    public function answering(string $path, string $body, int $status = 200): self
    {
        $this->answers[$path] = ['body' => $body, 'status' => $status];

        return $this;
    }

    public function unreachable(): self
    {
        $this->reachable = false;

        return $this;
    }

    public function status(string $baseUrl = 'http://localhost:2019'): AdminApiServerStatus
    {
        return new AdminApiServerStatus($this->adminApi($baseUrl));
    }

    public function adminApi(string $baseUrl = 'http://localhost:2019'): AdminApi
    {
        return new AdminApi(new MockHttpClient($this->answer(...)), $baseUrl);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $this->requestOptions[] = $options;
        $answer = $this->answers[(string) parse_url($url, PHP_URL_PATH)] ?? ['body' => '{"error":"not found"}', 'status' => 404];

        return $this->reachable
            ? new MockResponse($answer['body'], ['http_code' => $answer['status']])
            : new MockResponse('', ['error' => sprintf('Failed to connect to localhost port 2019 for "%s".', $url)]);
    }
}
