<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Closure;
use Symfony\Component\Clock\MockClock;
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

    /** @var array<string, int> */
    private array $cutOff = [];

    /** @var list<string> */
    private array $waitErrors = [];

    /** @var list<string> */
    private array $memoryKilledBeforeKill = [];

    /** @var list<string> */
    private array $unkillable = [];

    /** @var array<string, int> */
    private array $waits = [];

    /** @var array<string, list<string>> */
    private array $signals = [];

    /** @var array<string, list<string>> */
    private array $volumes = [];

    /** @var list<string> */
    private array $sockets = [];

    private readonly FakeNetworks $networks;

    private readonly FakeContainerLogs $logs;

    /** @var array<string, non-empty-list<string>> */
    private array $proxyHealth = [];

    private int $next = 0;
    private bool $down = false;
    private bool $garbledState = false;

    // Advanced by a wait that runs into its timeout, so a test sees the run time pass without sleeping.
    public readonly MockClock $clock;

    public function __construct()
    {
        $this->clock = new MockClock();
        $this->networks = new FakeNetworks(fn (): array => $this->containers);
        $this->logs = new FakeContainerLogs();
    }

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

    // Broken off at once while the job still runs, so a test plays a dropped connection to the engine.
    public function cutOffWaits(string $job, int $times): void
    {
        $this->cutOff[$job] = $times;
    }

    // Answers 200 with an Error in the body while the job still runs, because that is how the engine reports a wait that failed on its side.
    public function answerWaitsWithError(string $job): void
    {
        $this->waitErrors[] = $job;
    }

    // Memory kills the job in the last pause before its run time ends, so the kill finds it stopped and the engine answers 409.
    public function memoryKillBeforeTheKill(string $job): void
    {
        $this->memoryKilledBeforeKill[] = $job;
    }

    // The kill is accepted but the container keeps running, so a test plays an engine that never delivers SIGKILL.
    public function ignoreKills(string $job): void
    {
        $this->unkillable[] = $job;
    }

    /**
     * @param list<string> $paths
     */
    public function declareVolumes(string $image, array $paths): void
    {
        $this->volumes[$image] = $paths;
    }

    /**
     * @return list<string>
     */
    public function signalsOf(string $job): array
    {
        return $this->signals[$job] ?? [];
    }

    public function waitsOf(string $job): int
    {
        return $this->waits[$job] ?? 0;
    }

    public function hasCreated(string $job): bool
    {
        return isset($this->definitions['intakt-job-'.$job]);
    }

    public function hasCreatedProxy(string $job): bool
    {
        return isset($this->definitions['intakt-egress-'.$job]);
    }

    // What the job's proxy wrote to its standard output, so a test plays the requests the proxy refused.
    public function proxyLogs(string $job, string $log): void
    {
        $this->logs->proxyLogs($job, $log);
    }

    // Everything the job's container wrote to its standard error, the start check's lines and the job's own, so a test plays a failed check or a job that forges one.
    public function jobStandardError(string $job, string $log): void
    {
        $this->logs->jobStandardError($job, $log);
    }

    // Each state read of the job's proxy takes the next status ('exited' for a proxy that stopped) and the last one stays, so a test plays a proxy that starts slowly, stops or never gets healthy.
    public function proxyHealth(string $job, string $status, string ...$later): void
    {
        $this->proxyHealth[$job] = [$status, ...array_values($later)];
    }

    // The first frame claims more bytes than the body holds, so a test plays a log the engine framed wrongly.
    public function breakLogFrames(string $job): void
    {
        $this->logs->breakFrames($job);
    }

    public function leaveRunning(string $job, string $prefix = 'intakt-job-'): string
    {
        $id = $this->nextId();
        $this->containers[$id] = new FakeContainer($prefix.$job, $job, '');
        $this->containers[$id]->running = true;

        return $id;
    }

    public function leaveNetwork(string $job): void
    {
        $this->networks->leave($job);
    }

    /**
     * @return list<string>
     */
    public function networkNames(): array
    {
        return $this->networks->names();
    }

    /**
     * @return array<string, mixed>
     */
    public function networkOf(string $name): array
    {
        return $this->networks->of($name);
    }

    /**
     * @return array<string, mixed>
     */
    public function proxyDefinitionOf(string $job): array
    {
        return $this->definitions['intakt-egress-'.$job];
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
        return $this->definitions['intakt-job-'.$job];
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

        return $this->networks->answer($method, $this->pathOf($url), $options) ?? $this->answerOnContainers($method, $url, $options);
    }

    private function pathOf(string $url): string
    {
        return (string) preg_replace('#^/v1\.44/#', '', (string) parse_url($url, PHP_URL_PATH));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answerOnContainers(string $method, string $url, array $options): MockResponse
    {
        $path = $this->pathOf($url);

        return match (true) {
            'POST' === $method && 'containers/create' === $path => $this->create($url, $options),
            'GET' === $method && 'containers/json' === $path => $this->json($this->listing()),
            'GET' === $method && 1 === preg_match('#^images/(.+)/json$#', $path, $call) => $this->image(rawurldecode($call[1])),
            1 === preg_match('#^containers/([\w.-]+)/(start|wait|json|kill|logs)$#', $path, $call) => $this->onContainer($call[1], $call[2], [...$options, 'query' => $this->queryOf($url)]),
            'DELETE' === $method && 1 === preg_match('#^containers/([\w.-]+)$#', $path, $call) => $this->remove($call[1]),
            default => $this->json(['message' => 'page not found'], 404),
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function create(string $url, array $options): MockResponse
    {
        $query = $this->queryOf($url);
        /** @var array{Labels: array<string, string>, Entrypoint?: list<string>, HostConfig: array{NetworkMode: string, Mounts?: list<array{Source: string, Target: string}>}, NetworkingConfig?: array{EndpointsConfig: array<string, mixed>}} $definition */
        $definition = json_decode(is_string($options['body'] ?? null) ? $options['body'] : '{}', true, flags: JSON_THROW_ON_ERROR);
        $job = $definition['Labels']['intakt.job'];
        $name = is_string($query['name'] ?? null) ? $query['name'] : '';

        if (in_array($name, array_column($this->containers, 'name'), true)) {
            return $this->json(['message' => sprintf('Conflict. The container name "/%s" is already in use.', $name)], 409);
        }

        $networks = array_values(array_unique([$definition['HostConfig']['NetworkMode'], ...array_keys($definition['NetworkingConfig']['EndpointsConfig'] ?? [])]));
        $missing = $this->networks->missing($networks);

        if ([] !== $missing) {
            return $this->json(['message' => sprintf('network %s not found', implode(', ', $missing))], 404);
        }

        $output = array_column($definition['HostConfig']['Mounts'] ?? [], 'Source', 'Target')['/output'] ?? '';
        $id = $this->nextId();
        $this->containers[$id] = new FakeContainer($name, $job, $output);
        $this->containers[$id]->networks = $networks;
        $this->containers[$id]->startCheckMark = $definition['Entrypoint'][4] ?? '';
        $this->definitions[$name] = $definition;

        return $this->json(['Id' => $id], 201);
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

    // A JSON object keyed by path, or null, because that is the shape the engine's image inspection answers with.
    private function image(string $name): MockResponse
    {
        $paths = $this->volumes[$name] ?? [];

        return $this->json(['Config' => ['Volumes' => [] === $paths ? null : (object) array_fill_keys($paths, (object) [])]]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function onContainer(string $idOrName, string $call, array $options): MockResponse
    {
        $id = $this->idOf($idOrName);
        $container = $this->containers[$id] ?? null;

        if (null === $container) {
            return $this->json(['message' => 'No such container: '.$idOrName], 404);
        }

        return match ($call) {
            'start' => $this->start($container),
            'wait' => $this->wait($container, $options),
            'kill' => $this->kill($container, $options),
            'logs' => $this->logs->answer($container, is_array($options['query'] ?? null) ? $options['query'] : []),
            default => $this->state($container),
        };
    }

    private function idOf(string $idOrName): string
    {
        foreach ($this->containers as $id => $container) {
            if ($container->name === $idOrName) {
                return $id;
            }
        }

        return $idOrName;
    }

    private function start(FakeContainer $container): MockResponse
    {
        if (str_starts_with($container->name, 'intakt-egress-')) {
            $container->running = true;

            return new MockResponse('', ['http_code' => 204]);
        }

        if (in_array($container->job, $this->hanging, true)) {
            $container->running = true;

            return new MockResponse('', ['http_code' => 204]);
        }

        $behavior = $this->behaviors[$container->job] ?? static fn (string $output): array => [0, false];
        [$container->exitCode, $container->memoryKilled] = $behavior($container->outputDirectory);
        $container->running = isset($this->cutOff[$container->job]) || in_array($container->job, $this->waitErrors, true);

        return new MockResponse('', ['http_code' => 204]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function wait(FakeContainer $container, array $options): MockResponse
    {
        $this->waits[$container->job] = $this->waitsOf($container->job) + 1;

        if ($container->running && in_array($container->job, $this->waitErrors, true)) {
            return $this->json(['StatusCode' => -1, 'Error' => ['Message' => 'context canceled']]);
        }

        if ($container->running && ($this->cutOff[$container->job] ?? 0) > 0) {
            --$this->cutOff[$container->job];

            return new MockResponse('', ['error' => 'Connection reset by peer']);
        }

        if (!in_array($container->job, $this->hanging, true)) {
            $container->running = false;
        }

        if ($container->running) {
            $this->clock->sleep(is_numeric($options['timeout'] ?? null) ? (float) $options['timeout'] : 0.0);

            return new MockResponse('', ['error' => 'Idle timeout reached']);
        }

        return $this->json(['StatusCode' => $container->exitCode]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function kill(FakeContainer $container, array $options): MockResponse
    {
        $query = is_array($options['query'] ?? null) ? $options['query'] : [];

        // A signal that is no KILL reaches the job's script, which ends its session and exits on its own, so the job stops with its own status.
        if (is_string($query['signal'] ?? null) && 'KILL' !== $query['signal']) {
            $this->signals[$container->job][] = $query['signal'];
            [$container->running, $container->exitCode] = [false, 0];

            return new MockResponse('', ['http_code' => 204]);
        }

        if (in_array($container->job, $this->unkillable, true)) {
            return new MockResponse('', ['http_code' => 204]);
        }

        if (in_array($container->job, $this->memoryKilledBeforeKill, true)) {
            [$container->running, $container->memoryKilled, $container->exitCode] = [false, true, 137];

            return $this->json(['message' => sprintf('Container %s is not running', $container->name)], 409);
        }

        $container->running = false;
        $container->exitCode = 137;

        return new MockResponse('', ['http_code' => 204]);
    }

    private function state(FakeContainer $container): MockResponse
    {
        if ($this->garbledState) {
            return $this->json(['State' => 'fine']);
        }

        if (str_starts_with($container->name, 'intakt-egress-')) {
            return $this->proxyState($container);
        }

        return $this->json(['State' => ['Running' => $container->running, 'OOMKilled' => $container->memoryKilled, 'ExitCode' => $container->exitCode]]);
    }

    // Healthy unless a test said otherwise, with the health status the engine keeps for a container it runs health checks in.
    private function proxyState(FakeContainer $container): MockResponse
    {
        $statuses = $this->proxyHealth[$container->job] ?? ['healthy'];
        $status = array_shift($statuses);

        if ([] !== $statuses) {
            $this->proxyHealth[$container->job] = $statuses;
        }

        if ('exited' === $status) {
            [$container->running, $container->exitCode, $status] = [false, 1, 'unhealthy'];
        }

        return $this->json(['State' => ['Running' => $container->running, 'OOMKilled' => false, 'ExitCode' => $container->exitCode, 'Health' => ['Status' => $status]]]);
    }

    private function remove(string $idOrName): MockResponse
    {
        $id = $this->idOf($idOrName);

        if (!isset($this->containers[$id])) {
            return $this->json(['message' => 'No such container: '.$idOrName], 404);
        }

        unset($this->containers[$id]);

        return new MockResponse('', ['http_code' => 204]);
    }
}
