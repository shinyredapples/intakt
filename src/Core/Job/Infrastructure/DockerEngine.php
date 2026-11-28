<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Closure;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobServices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class DockerEngine
{
    // Pinned, so a newer engine still answers in the shape read here (1.44 is Docker Engine 25).
    private const string API = 'http://docker/v1.44/';
    private const string CONTAINER_ID = '/^[A-Za-z0-9]{1,128}$/D';
    private const string NETWORK_NAME = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D';
    // Generous, because a step such as compiling an extension may print nothing for minutes; a whole build gets an hour before it counts as stuck.
    private const float BUILD_IDLE_SECONDS = 600.0;
    private const float BUILD_SECONDS = 3600.0;

    private HttpClientInterface $client;

    public function __construct(
        HttpClientInterface $client,
        #[Autowire(param: JobServices::DOCKER_SOCKET)]
        private string $socket,
    ) {
        // Set as bindto, because the curl client connects through the Unix socket named there.
        $this->client = $client->withOptions(['base_uri' => self::API, 'bindto' => $socket]);
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function create(string $name, array $definition): string
    {
        $answer = $this->send('POST', 'containers/create', ['query' => ['name' => $name], 'json' => $definition])->accepting(201);

        return $this->containerIdOf($answer['Id'] ?? null, 'containers/create');
    }

    /**
     * @param Closure(int): string $context
     */
    // With rm and forcerm, so no container of a step is left behind, also after a failed one; the context is sent in chunks it hands out, so a large repository does not have to fit in memory.
    public function build(Closure $context, string $tag, string $dockerfile): ImageBuild
    {
        return $this->send('POST', 'build', [
            'query' => ['t' => $tag, 'dockerfile' => $dockerfile, 'rm' => 'true', 'forcerm' => 'true'],
            'headers' => ['Content-Type' => 'application/x-tar'],
            'body' => $context,
            'timeout' => self::BUILD_IDLE_SECONDS,
            'max_duration' => self::BUILD_SECONDS,
        ])->built(200);
    }

    public function start(string $container): void
    {
        $this->send('POST', sprintf('containers/%s/start', $container))->accepting(204, 304);
    }

    // False when the wait ended without an answer (the deadline passed, the connection broke or the body holds no exit code), so the caller reads the state to tell which.
    public function waitForExit(string $container, float $seconds): bool
    {
        try {
            $response = $this->request('POST', sprintf('containers/%s/wait', $container), ['timeout' => $seconds, 'max_duration' => $seconds]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            return false;
        }

        if (200 !== $status) {
            throw DockerEngineFailed::refused('containers/wait', $status, '');
        }

        return $this->holdsAnExitCode($body);
    }

    /**
     * @return list<string>
     */
    public function imageVolumes(string $image): array
    {
        $config = $this->send('GET', sprintf('images/%s/json', rawurlencode($image)))->accepting(200)['Config'] ?? null;

        if (!is_array($config)) {
            throw DockerEngineFailed::unexpected('images/json');
        }

        // Absent or null means none, because the engine leaves an empty map out of its answer.
        $volumes = $config['Volumes'] ?? [];

        if (!is_array($volumes)) {
            throw DockerEngineFailed::unexpected('images/json');
        }

        return array_map(strval(...), array_keys($volumes));
    }

    public function state(string $container): ContainerState
    {
        $state = $this->send('GET', sprintf('containers/%s/json', $container))->accepting(200)['State'] ?? null;

        if (!is_array($state) || !is_bool($state['Running'] ?? null) || !is_bool($state['OOMKilled'] ?? null) || !is_int($state['ExitCode'] ?? null)) {
            throw DockerEngineFailed::unexpected('containers/json');
        }

        return new ContainerState($state['Running'], $state['OOMKilled'], $state['ExitCode']);
    }

    // A 409 is accepted, because it means the container stopped on its own meanwhile, which is what the kill wanted.
    public function kill(string $container): void
    {
        $this->send('POST', sprintf('containers/%s/kill', $container))->accepting(204, 409);
    }

    // USR1 to the job's first process, which the build script traps to end the builder's session only; a 409 or 404 is accepted, because the job ended meanwhile.
    public function endSession(string $container): void
    {
        $this->send('POST', sprintf('containers/%s/kill', $container), ['query' => ['signal' => 'USR1']])->accepting(204, 404, 409);
    }

    // A 404 is accepted, because it means the container is gone already, which is what the removal wanted; v removes the anonymous volumes an image declares with it.
    public function remove(string $container): void
    {
        $this->send('DELETE', sprintf('containers/%s', $container), ['query' => ['force' => 'true', 'v' => 'true']])->accepting(204, 404);
    }

    // Internal, so the engine gives the network no route out and only a container attached to it as well can reach outside; without an address of the host on its bridge and without IPv6, so a job cannot reach a service on the host past its proxy either.
    public function createNetwork(string $name, JobId $job): void
    {
        $network = [
            'Name' => $name,
            'Internal' => true,
            'EnableIPv6' => false,
            'Options' => ['com.docker.network.bridge.inhibit_ipv4' => 'true'],
            'Labels' => [JobServices::JOB_LABEL => $job->value],
        ];

        $this->send('POST', 'networks/create', ['json' => $network])->accepting(201);
    }

    // Stopped wins over the health status, because the engine keeps the last status of a container that exited.
    public function health(string $container): ContainerHealth
    {
        $state = $this->send('GET', sprintf('containers/%s/json', $container))->accepting(200)['State'] ?? null;
        $status = is_array($state) && is_array($state['Health'] ?? null) ? ($state['Health']['Status'] ?? null) : null;

        if (!is_array($state) || !is_bool($state['Running'] ?? null) || !is_string($status)) {
            throw DockerEngineFailed::unexpected('containers/json');
        }

        if (!$state['Running']) {
            return ContainerHealth::Stopped;
        }

        $health = ContainerHealth::tryFrom($status);

        if (null === $health || ContainerHealth::Stopped === $health) {
            throw DockerEngineFailed::unexpected('containers/json');
        }

        return $health;
    }

    // A 404 is accepted, because it means the network is gone already, which is what the removal wanted.
    public function removeNetwork(string $name): void
    {
        $this->send('DELETE', sprintf('networks/%s', $name))->accepting(204, 404);
    }

    public function logs(string $container, int $lines): string
    {
        return $this->send('GET', sprintf('containers/%s/logs', $container), ['query' => ['stdout' => 'true', 'tail' => (string) $lines]])->demultiplexed(200);
    }

    // Without a tail and cut after a number of bytes, because the start of the log is what counts and the job could write far more after it.
    public function standardErrorHead(string $container, int $bytes): string
    {
        $path = sprintf('containers/%s/logs', $container);

        try {
            $response = $this->request('GET', $path, ['query' => ['stderr' => 'true'], 'buffer' => false]);

            return new EngineAnswer($path, $response->getStatusCode(), $this->headOf($response, $bytes))->demultiplexedHead(200);
        } catch (TransportExceptionInterface $failure) {
            throw DockerEngineFailed::unreachable($this->socket, $failure);
        }
    }

    /**
     * @return list<JobNetwork>
     */
    public function jobNetworks(): array
    {
        $filters = json_encode(['label' => [JobServices::JOB_LABEL]], JSON_THROW_ON_ERROR);
        $listed = $this->send('GET', 'networks', ['query' => ['filters' => $filters]])->accepting(200);

        if (!array_is_list($listed)) {
            throw DockerEngineFailed::unexpected('networks');
        }

        return array_values(array_filter(array_map($this->jobNetworkOf(...), $listed)));
    }

    /**
     * @return list<JobContainer>
     */
    public function jobContainers(): array
    {
        $filters = json_encode(['label' => [JobServices::JOB_LABEL]], JSON_THROW_ON_ERROR);
        $listed = $this->send('GET', 'containers/json', ['query' => ['all' => 'true', 'filters' => $filters]])->accepting(200);

        if (!array_is_list($listed)) {
            throw DockerEngineFailed::unexpected('containers/json');
        }

        return array_values(array_filter(array_map($this->jobContainerOf(...), $listed)));
    }

    // The rest is never fetched, so a flood after the head costs no memory.
    private function headOf(ResponseInterface $response, int $bytes): string
    {
        $head = '';

        foreach ($this->client->stream($response) as $chunk) {
            $head .= $chunk->getContent();

            if (strlen($head) > $bytes) {
                $response->cancel();

                break;
            }
        }

        return substr($head, 0, $bytes);
    }

    private function jobNetworkOf(mixed $listed): ?JobNetwork
    {
        $job = is_array($listed) && is_array($listed['Labels'] ?? null) ? ($listed['Labels'][JobServices::JOB_LABEL] ?? null) : null;

        // Skipped because only a label Intakt could have written names a job whose lock decides the network's fate.
        if (!is_string($job) || !JobId::isValid($job)) {
            return null;
        }

        $name = $listed['Name'] ?? null;

        if (!is_string($name) || 1 !== preg_match(self::NETWORK_NAME, $name)) {
            throw DockerEngineFailed::unexpected('networks');
        }

        return new JobNetwork($name, new JobId($job));
    }

    private function jobContainerOf(mixed $listed): ?JobContainer
    {
        $job = is_array($listed) && is_array($listed['Labels'] ?? null) ? ($listed['Labels'][JobServices::JOB_LABEL] ?? null) : null;

        // Skipped because only a label Intakt could have written names a job whose lock decides the container's fate.
        if (!is_string($job) || !JobId::isValid($job)) {
            return null;
        }

        return new JobContainer($this->containerIdOf($listed['Id'] ?? null, 'containers/json'), new JobId($job));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, array $options = []): EngineAnswer
    {
        try {
            $response = $this->request($method, $path, $options);

            return new EngineAnswer($path, $response->getStatusCode(), $response->getContent(false));
        } catch (TransportExceptionInterface $failure) {
            throw DockerEngineFailed::unreachable($this->socket, $failure);
        }
    }

    private function containerIdOf(mixed $id, string $call): string
    {
        if (!is_string($id) || 1 !== preg_match(self::CONTAINER_ID, $id)) {
            throw DockerEngineFailed::unexpected($call);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $options
     */
    // Checked first, because without the socket file the curl client reads bindto as a network interface and reports that instead.
    private function request(string $method, string $path, array $options): ResponseInterface
    {
        if (!file_exists($this->socket)) {
            throw DockerEngineFailed::noSocket($this->socket);
        }

        return $this->client->request($method, $path, $options);
    }

    // The engine sends the 200 header before the wait ends and reports a wait that failed meanwhile as an Error in the body, so only a plain exit code counts.
    private function holdsAnExitCode(string $body): bool
    {
        $answer = json_decode($body, true);

        return is_array($answer) && is_int($answer['StatusCode'] ?? null) && null === ($answer['Error'] ?? null);
    }
}
