<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

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
    public const string JOB_LABEL = 'intakt.job';
    private const string CONTAINER_ID = '/^[A-Za-z0-9]{1,128}$/D';

    private HttpClientInterface $client;

    public function __construct(
        HttpClientInterface $client,
        #[Autowire(param: JobServices::DOCKER_SOCKET)]
        private string $socket,
    ) {
        // The curl client reads bindto as the Unix socket to connect through.
        $this->client = $client->withOptions(['base_uri' => self::API, 'bindto' => $socket]);
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function create(string $name, array $definition): string
    {
        $answer = $this->call('POST', 'containers/create', ['query' => ['name' => $name], 'json' => $definition], [201]);

        return $this->containerIdOf($answer['Id'] ?? null, 'containers/create');
    }

    public function start(string $container): void
    {
        $this->call('POST', sprintf('containers/%s/start', $container), [], [204, 304]);
    }

    // False when the wait ended without an answer (the deadline passed or the connection broke), so the caller reads the state to tell which.
    public function waitForExit(string $container, int $seconds): bool
    {
        try {
            $response = $this->request('POST', sprintf('containers/%s/wait', $container), ['timeout' => $seconds, 'max_duration' => $seconds]);
            $status = $response->getStatusCode();
            $response->getContent(false);
        } catch (TransportExceptionInterface) {
            return false;
        }

        if (200 !== $status) {
            throw DockerEngineFailed::refused('containers/wait', $status, '');
        }

        return true;
    }

    public function state(string $container): ContainerState
    {
        $state = $this->call('GET', sprintf('containers/%s/json', $container), [], [200])['State'] ?? null;

        if (!is_array($state) || !is_bool($state['Running'] ?? null) || !is_bool($state['OOMKilled'] ?? null) || !is_int($state['ExitCode'] ?? null)) {
            throw DockerEngineFailed::unexpected('containers/json');
        }

        return new ContainerState($state['Running'], $state['OOMKilled'], $state['ExitCode']);
    }

    // A 409 means the container stopped on its own meanwhile, which is what the kill wanted.
    public function kill(string $container): void
    {
        $this->call('POST', sprintf('containers/%s/kill', $container), [], [204, 409]);
    }

    // A 404 means it is gone already, which is what the removal wanted.
    public function remove(string $container): void
    {
        $this->call('DELETE', sprintf('containers/%s', $container), ['query' => ['force' => 'true']], [204, 404]);
    }

    /**
     * @return list<JobContainer>
     */
    public function jobContainers(): array
    {
        $listed = $this->call('GET', 'containers/json', ['query' => ['all' => 'true', 'filters' => json_encode(['label' => [self::JOB_LABEL]], JSON_THROW_ON_ERROR)]], [200]);

        if (!array_is_list($listed)) {
            throw DockerEngineFailed::unexpected('containers/json');
        }

        return array_values(array_filter(array_map($this->jobContainerOf(...), $listed)));
    }

    private function jobContainerOf(mixed $listed): ?JobContainer
    {
        $job = is_array($listed) && is_array($listed['Labels'] ?? null) ? ($listed['Labels'][self::JOB_LABEL] ?? null) : null;

        // Skipped because only a label Intakt could have written names a job whose lock decides the container's fate.
        if (!is_string($job) || !JobId::isValid($job)) {
            return null;
        }

        return new JobContainer($this->containerIdOf($listed['Id'] ?? null, 'containers/json'), new JobId($job));
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
     * @param list<int>            $accepted
     *
     * @return array<mixed>
     */
    private function call(string $method, string $path, array $options, array $accepted): array
    {
        try {
            $response = $this->request($method, $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $failure) {
            throw DockerEngineFailed::unreachable($this->socket, $failure);
        }

        $answer = '' === $body ? [] : json_decode($body, true);

        if (!in_array($status, $accepted, true)) {
            throw DockerEngineFailed::refused($path, $status, is_array($answer) && is_string($answer['message'] ?? null) ? $answer['message'] : '');
        }

        return is_array($answer) ? $answer : throw DockerEngineFailed::unexpected($path);
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
}
