<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Closure;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Answers the Docker Engine API calls the job runner makes, because the build sandbox has no Docker daemon.
final class FakeDockerEngine
{
    // An empty file, because the engine client only checks that its socket exists before the mock answers.
    public const string SOCKET = __DIR__.'/Fixtures/docker.sock';

    /** @var array<string, array{name: string, job: string, definition: array<string, mixed>, running: bool, oom: bool, exit: int}> */
    private array $containers = [];

    /** @var array<string, Closure(string): array{int, bool}> */
    private array $behaviors = [];

    /** @var array<string, array<string, mixed>> */
    private array $definitions = [];

    /** @var list<string> */
    private array $hanging = [];

    /** @var list<string> */
    private array $sockets = [];

    private int $next = 0;
    private bool $down = false;
    private bool $garbledState = false;

    public function client(): MockHttpClient
    {
        return new MockHttpClient($this->answer(...));
    }

    /**
     * @param Closure(string): array{int, bool} $behavior gets the output directory, returns the exit code and whether memory killed it
     */
    public function behaveAs(string $job, Closure $behavior): void
    {
        $this->behaviors[$job] = $behavior;
    }

    public function hang(string $job): void
    {
        $this->hanging[] = $job;
    }

    public function leaveRunning(string $job): string
    {
        $id = $this->nextId();
        $this->containers[$id] = ['name' => 'intakt-job-'.$job, 'job' => $job, 'definition' => [], 'running' => true, 'oom' => false, 'exit' => 0];

        return $id;
    }

    public function goDown(): void
    {
        $this->down = true;
    }

    public function garbleState(): void
    {
        $this->garbledState = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function definitionOf(string $job): array
    {
        return $this->definitions[$job];
    }

    /**
     * @return list<string>
     */
    public function containerIds(): array
    {
        return array_keys($this->containers);
    }

    /**
     * @return list<string>
     */
    public function sockets(): array
    {
        return array_values(array_unique($this->sockets));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): MockResponse
    {
        $this->sockets[] = is_string($options['bindto'] ?? null) ? $options['bindto'] : '';

        if ($this->down) {
            return new MockResponse('', ['error' => 'Connection refused']);
        }

        $path = (string) preg_replace('#^/v1\.44/#', '', (string) parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return match (true) {
            'POST' === $method && 'containers/create' === $path => $this->create((string) ($query['name'] ?? ''), is_string($options['body'] ?? null) ? $options['body'] : '{}'),
            'GET' === $method && 'containers/json' === $path => $this->json($this->listing()),
            1 === preg_match('#^containers/(\w+)/(start|wait|json|kill)$#', $path, $call) => $this->onContainer($call[1], $call[2]),
            'DELETE' === $method && 1 === preg_match('#^containers/(\w+)$#', $path, $call) => $this->remove($call[1]),
            default => $this->json(['message' => 'page not found'], 404),
        };
    }

    private function create(string $name, string $body): MockResponse
    {
        /** @var array{Labels: array<string, string>} $definition */
        $definition = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $job = $definition['Labels']['intakt.job'];
        $id = $this->nextId();
        $this->containers[$id] = ['name' => $name, 'job' => $job, 'definition' => $definition, 'running' => false, 'oom' => false, 'exit' => 0];
        $this->definitions[$job] = $definition;

        return $this->json(['Id' => $id], 201);
    }

    private function onContainer(string $id, string $call): MockResponse
    {
        if (!isset($this->containers[$id])) {
            return $this->json(['message' => 'No such container: '.$id], 404);
        }

        return match ($call) {
            'start' => $this->start($id),
            'wait' => $this->containers[$id]['running'] ? new MockResponse('', ['error' => 'Idle timeout reached']) : $this->json(['StatusCode' => $this->containers[$id]['exit']]),
            'kill' => $this->kill($id),
            default => $this->state($id),
        };
    }

    private function start(string $id): MockResponse
    {
        $job = $this->containers[$id]['job'];

        if (in_array($job, $this->hanging, true)) {
            $this->containers[$id]['running'] = true;

            return new MockResponse('', ['http_code' => 204]);
        }

        [$exit, $oom] = ($this->behaviors[$job] ?? static fn (string $output): array => [0, false])($this->outputOf($id));
        $this->containers[$id]['exit'] = $exit;
        $this->containers[$id]['oom'] = $oom;

        return new MockResponse('', ['http_code' => 204]);
    }

    private function kill(string $id): MockResponse
    {
        $this->containers[$id]['running'] = false;
        $this->containers[$id]['exit'] = 137;

        return new MockResponse('', ['http_code' => 204]);
    }

    private function state(string $id): MockResponse
    {
        if ($this->garbledState) {
            return $this->json(['State' => 'fine']);
        }

        $container = $this->containers[$id];

        return $this->json(['State' => ['Running' => $container['running'], 'OOMKilled' => $container['oom'], 'ExitCode' => $container['exit']]]);
    }

    private function remove(string $id): MockResponse
    {
        if (!isset($this->containers[$id])) {
            return $this->json(['message' => 'No such container: '.$id], 404);
        }

        unset($this->containers[$id]);

        return new MockResponse('', ['http_code' => 204]);
    }

    /**
     * @return list<array{Id: string, Names: list<string>, Labels: array<string, string>}>
     */
    private function listing(): array
    {
        $listing = [];

        foreach ($this->containers as $id => $container) {
            $listing[] = ['Id' => $id, 'Names' => ['/'.$container['name']], 'Labels' => ['intakt.job' => $container['job']]];
        }

        return $listing;
    }

    private function outputOf(string $id): string
    {
        /** @var list<array{Source: string, Target: string}> $mounts */
        $mounts = $this->containers[$id]['definition']['HostConfig']['Mounts'] ?? [];

        foreach ($mounts as $mount) {
            if ('/output' === $mount['Target']) {
                return $mount['Source'];
            }
        }

        return '';
    }

    private function nextId(): string
    {
        return sprintf('c0ffee%06d', ++$this->next);
    }

    /**
     * @param array<mixed> $body
     */
    private function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
