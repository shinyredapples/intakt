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

    /** @var array<string, FakeContainer> */
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

    // The behavior gets the output directory and returns the exit code and whether memory killed the job, so a test plays one run.
    /**
     * @param Closure(string): array{int, bool} $behavior
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
        $this->containers[$id] = new FakeContainer('intakt-job-'.$job, $job, '');
        $this->containers[$id]->running = true;

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

        return match (true) {
            'POST' === $method && 'containers/create' === $path => $this->create($url, $options),
            'GET' === $method && 'containers/json' === $path => $this->json($this->listing()),
            1 === preg_match('#^containers/(\w+)/(start|wait|json|kill)$#', $path, $call) => $this->onContainer($call[1], $call[2]),
            'DELETE' === $method && 1 === preg_match('#^containers/(\w+)$#', $path, $call) => $this->remove($call[1]),
            default => $this->json(['message' => 'page not found'], 404),
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function create(string $url, array $options): MockResponse
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        /** @var array{Labels: array<string, string>, HostConfig: array{Mounts: list<array{Source: string, Target: string}>}} $definition */
        $definition = json_decode(is_string($options['body'] ?? null) ? $options['body'] : '{}', true, flags: JSON_THROW_ON_ERROR);
        $job = $definition['Labels']['intakt.job'];
        $output = array_column($definition['HostConfig']['Mounts'], 'Source', 'Target')['/output'] ?? '';
        $id = $this->nextId();
        $this->containers[$id] = new FakeContainer(is_string($query['name'] ?? null) ? $query['name'] : '', $job, $output);
        $this->definitions[$job] = $definition;

        return $this->json(['Id' => $id], 201);
    }

    private function onContainer(string $id, string $call): MockResponse
    {
        $container = $this->containers[$id] ?? null;

        if (null === $container) {
            return $this->json(['message' => 'No such container: '.$id], 404);
        }

        return match ($call) {
            'start' => $this->start($container),
            'wait' => $container->running ? new MockResponse('', ['error' => 'Idle timeout reached']) : $this->json(['StatusCode' => $container->exitCode]),
            'kill' => $this->kill($container),
            default => $this->state($container),
        };
    }

    private function start(FakeContainer $container): MockResponse
    {
        if (in_array($container->job, $this->hanging, true)) {
            $container->running = true;

            return new MockResponse('', ['http_code' => 204]);
        }

        $behavior = $this->behaviors[$container->job] ?? static fn (string $output): array => [0, false];
        [$container->exitCode, $container->memoryKilled] = $behavior($container->outputDirectory);

        return new MockResponse('', ['http_code' => 204]);
    }

    private function kill(FakeContainer $container): MockResponse
    {
        $container->running = false;
        $container->exitCode = 137;

        return new MockResponse('', ['http_code' => 204]);
    }

    private function state(FakeContainer $container): MockResponse
    {
        if ($this->garbledState) {
            return $this->json(['State' => 'fine']);
        }

        return $this->json(['State' => ['Running' => $container->running, 'OOMKilled' => $container->memoryKilled, 'ExitCode' => $container->exitCode]]);
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
            $listing[] = ['Id' => $id, 'Names' => ['/'.$container->name], 'Labels' => ['intakt.job' => $container->job]];
        }

        return $listing;
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
