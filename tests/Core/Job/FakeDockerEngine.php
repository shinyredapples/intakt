<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Closure;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Response\MockResponse;

// A Docker Engine that keeps the containers and networks it is told about, so a test reads what was created and what is left.
final class FakeDockerEngine
{
    public int $exitCode = 0;
    public bool $oomKilled = false;
    public bool $timesOut = false;
    public bool $refusesToStartTheJob = false;
    public string $proxyLog = '';
    public ?Closure $onJobStart = null;

    /** @var array<string, array{name: string, body: array<string, mixed>}> */
    public array $containers = [];

    /** @var array<string, array{name: string, labels: array<string, string>}> */
    public array $networks = [];

    /** @var array<string, array<string, mixed>> the body of every container ever created, by name */
    public array $created = [];

    /** @var list<string> */
    public array $calls = [];

    private int $counter = 0;

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $path = (string) preg_replace('#^/v1\.\d+#', '', (string) parse_url($url, PHP_URL_PATH));
        $this->calls[] = $method.' '.$path;
        $body = is_string($options['body'] ?? null) ? (json_decode($options['body'], true) ?? []) : [];

        return match (true) {
            'POST /containers/create' === $method.' '.$path => $this->created($url, $body),
            'POST /networks/create' === $method.' '.$path => $this->networkCreated($body),
            'GET /containers/json' === $method.' '.$path => $this->listed($this->containers, static fn (array $c): array => $c['body']['Labels'] ?? []),
            'GET /networks' === $method.' '.$path => $this->listed($this->networks, static fn (array $n): array => $n['labels']),
            1 === preg_match('#^/containers/([^/]+)/(\w+)$#', $path, $m) => $this->containerCall($method, $m[1], $m[2]),
            1 === preg_match('#^/containers/([^/]+)$#', $path, $m) && 'DELETE' === $method => $this->removed($this->containers, $m[1]),
            1 === preg_match('#^/networks/([^/]+)$#', $path, $m) && 'DELETE' === $method => $this->removed($this->networks, $m[1]),
            1 === preg_match('#^/networks/([^/]+)/connect$#', $path) => new MockResponse('{}'),
            default => new MockResponse('{"message":"no route"}', ['http_code' => 404]),
        };
    }

    /**
     * @return list<string>
     */
    public function namesOfContainers(): array
    {
        return array_values(array_map(static fn (array $c): string => $c['name'], $this->containers));
    }

    /**
     * @return array<string, mixed>
     */
    public function jobBody(): array
    {
        foreach ($this->created as $name => $body) {
            if (!str_ends_with($name, '-proxy')) {
                return $body;
            }
        }

        return [];
    }

    public function seedContainer(string $name, string $jobId): string
    {
        $id = 'c'.++$this->counter;
        $this->containers[$id] = ['name' => $name, 'body' => ['Labels' => ['intakt.job' => $jobId]]];

        return $id;
    }

    public function seedNetwork(string $name, string $jobId): string
    {
        $id = 'n'.++$this->counter;
        $this->networks[$id] = ['name' => $name, 'labels' => ['intakt.job' => $jobId]];

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function created(string $url, array $body): MockResponse
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $id = 'c'.++$this->counter;
        $this->containers[$id] = ['name' => (string) $query['name'], 'body' => $body];
        $this->created[(string) $query['name']] = $body;

        return new MockResponse(json_encode(['Id' => $id], JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function networkCreated(array $body): MockResponse
    {
        $id = 'n'.++$this->counter;
        $this->networks[$id] = ['name' => (string) $body['Name'], 'labels' => $body['Labels']];

        return new MockResponse(json_encode(['Id' => $id], JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }

    private function containerCall(string $method, string $id, string $action): MockResponse
    {
        $isProxy = str_ends_with($this->containers[$id]['name'] ?? '', '-proxy');

        return match ($action) {
            'start' => $this->started($id, $isProxy),
            'wait' => $this->timesOut ? throw new TimeoutException('Idle timeout reached.') : new MockResponse(json_encode(['StatusCode' => $this->exitCode], JSON_THROW_ON_ERROR)),
            'json' => new MockResponse(json_encode(['State' => ['OOMKilled' => $this->oomKilled]], JSON_THROW_ON_ERROR)),
            'logs' => new MockResponse($this->framed($this->proxyLog)),
            default => new MockResponse('', ['http_code' => 204]),
        };
    }

    private function started(string $id, bool $isProxy): MockResponse
    {
        if (!$isProxy && $this->refusesToStartTheJob) {
            return new MockResponse('{"message":"cannot start"}', ['http_code' => 500]);
        }

        if (!$isProxy && null !== $this->onJobStart) {
            ($this->onJobStart)($this->containers[$id]['body']);
        }

        return new MockResponse('', ['http_code' => 204]);
    }

    /**
     * @param array<string, array<string, mixed>> $resources
     */
    private function removed(array &$resources, string $id): MockResponse
    {
        if (!isset($resources[$id])) {
            return new MockResponse('{"message":"gone"}', ['http_code' => 404]);
        }

        unset($resources[$id]);

        return new MockResponse('', ['http_code' => 204]);
    }

    /**
     * @param array<string, mixed>                $resources
     * @param Closure(array<string, mixed>): array<string, string> $labels
     */
    private function listed(array $resources, Closure $labels): MockResponse
    {
        $items = [];

        foreach ($resources as $id => $resource) {
            $items[] = ['Id' => $id, 'Labels' => $labels($resource)];
        }

        return new MockResponse(json_encode($items, JSON_THROW_ON_ERROR));
    }

    private function framed(string $text): string
    {
        return '' === $text ? '' : "\x01\x00\x00\x00".pack('N', strlen($text)).$text;
    }
}
