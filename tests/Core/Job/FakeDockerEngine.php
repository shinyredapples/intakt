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

    /** @var array<string, array{name: string, labels: array<string, string>}> */
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
        $route = $method.' '.$path;
        $this->calls[] = $route;
        $body = is_string($options['body'] ?? null) ? json_decode($options['body'], true) : [];
        $body = is_array($body) ? $body : [];

        return match (true) {
            'POST /containers/create' === $route => $this->created($url, $body),
            'POST /networks/create' === $route => $this->networkCreated($body),
            'GET /containers/json' === $route => $this->listed($this->containers),
            'GET /networks' === $route => $this->listed($this->networks),
            1 === preg_match('#^/containers/([^/]+)/(\w+)$#', $path, $m) => $this->containerCall($m[1], $m[2]),
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

    /**
     * @return array{Memory: int, MemorySwap: int, NanoCpus: int, NetworkMode: string, ReadonlyRootfs: bool, Mounts: list<array<string, mixed>>, Tmpfs: array<string, string>, CapDrop: list<string>, SecurityOpt: list<string>}
     */
    public function hostConfig(): array
    {
        /** @var array{Memory: int, MemorySwap: int, NanoCpus: int, NetworkMode: string, ReadonlyRootfs: bool, Mounts: list<array<string, mixed>>, Tmpfs: array<string, string>, CapDrop: list<string>, SecurityOpt: list<string>} $host */
        $host = $this->jobBody()['HostConfig'];

        return $host;
    }

    /**
     * @return list<string>
     */
    public function environment(): array
    {
        /** @var list<string> $environment */
        $environment = $this->jobBody()['Env'];

        return $environment;
    }

    public function seedContainer(string $name, string $jobId): string
    {
        $id = 'c'.++$this->counter;
        $this->containers[$id] = ['name' => $name, 'labels' => ['intakt.job' => $jobId]];

        return $id;
    }

    public function seedNetwork(string $name, string $jobId): string
    {
        $id = 'n'.++$this->counter;
        $this->networks[$id] = ['name' => $name, 'labels' => ['intakt.job' => $jobId]];

        return $id;
    }

    /**
     * @param array<mixed> $body
     */
    private function created(string $url, array $body): MockResponse
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $id = 'c'.++$this->counter;
        $name = is_string($query['name'] ?? null) ? $query['name'] : '';
        /** @var array<string, string> $labels */
        $labels = $body['Labels'] ?? [];
        $this->containers[$id] = ['name' => $name, 'labels' => $labels];
        /** @var array<string, mixed> $body */
        $this->created[$name] = $body;

        return new MockResponse(json_encode(['Id' => $id], JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }

    /**
     * @param array<mixed> $body
     */
    private function networkCreated(array $body): MockResponse
    {
        $id = 'n'.++$this->counter;
        /** @var array<string, string> $labels */
        $labels = $body['Labels'] ?? [];
        $name = is_string($body['Name'] ?? null) ? $body['Name'] : '';
        $this->networks[$id] = ['name' => $name, 'labels' => $labels];

        return new MockResponse(json_encode(['Id' => $id], JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }

    private function containerCall(string $id, string $action): MockResponse
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
            ($this->onJobStart)($this->containers[$id]['name']);
        }

        return new MockResponse('', ['http_code' => 204]);
    }

    /**
     * @param array<string, array{name: string, labels: array<string, string>}> $resources
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
     * @param array<string, array{name: string, labels: array<string, string>}> $resources
     */
    private function listed(array $resources): MockResponse
    {
        $items = [];

        foreach ($resources as $id => $resource) {
            $items[] = ['Id' => $id, 'Labels' => $resource['labels']];
        }

        return new MockResponse(json_encode($items, JSON_THROW_ON_ERROR));
    }

    private function framed(string $text): string
    {
        return '' === $text ? '' : "\x01\x00\x00\x00".pack('N', strlen($text)).$text;
    }
}
