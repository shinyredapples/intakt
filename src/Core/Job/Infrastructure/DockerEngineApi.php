<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobServices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// The only class that knows the Engine API's paths and JSON shapes, so a change of the API touches one file; it never shells out to a docker command.
final readonly class DockerEngineApi
{
    private const string BASE = 'http://localhost/v1.43';
    private const int LOG_LINES = 1000;

    private HttpClientInterface $client;

    public function __construct(
        #[Autowire(param: JobServices::DOCKER_SOCKET)]
        string $socket,
        ?HttpClientInterface $client,
    ) {
        $this->client = $client ?? HttpClient::create(['bindto' => $socket]);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function createContainer(string $name, array $body): string
    {
        return $this->idOf($this->call('POST', '/containers/create', ['query' => ['name' => $name], 'json' => $body]));
    }

    /**
     * @param array<string, string> $labels
     */
    public function createInternalNetwork(string $name, array $labels): string
    {
        return $this->idOf($this->call('POST', '/networks/create', ['json' => ['Name' => $name, 'Internal' => true, 'Labels' => $labels]]));
    }

    public function connect(string $networkId, string $containerId, string $alias): void
    {
        $this->call('POST', '/networks/'.$networkId.'/connect', ['json' => ['Container' => $containerId, 'EndpointConfig' => ['Aliases' => [$alias]]]]);
    }

    public function start(string $containerId): void
    {
        $this->checked($this->request('POST', '/containers/'.$containerId.'/start'), 304);
    }

    // Null when the container is still running after the time limit, because the caller must then kill it.
    public function wait(string $containerId, int $timeoutSeconds): ?int
    {
        try {
            $exit = $this->call('POST', '/containers/'.$containerId.'/wait', ['timeout' => $timeoutSeconds])->toArray()['StatusCode'] ?? null;
        } catch (TimeoutExceptionInterface) {
            return null;
        }

        return is_int($exit) ? $exit : throw new DockerEngineError('The Docker Engine did not answer a wait with a status code.');
    }

    public function kill(string $containerId): void
    {
        $this->checked($this->request('POST', '/containers/'.$containerId.'/kill'), 409);
    }

    public function memoryKilled(string $containerId): bool
    {
        $state = $this->call('GET', '/containers/'.$containerId.'/json')->toArray()['State'] ?? null;

        // Only the engine's own flag tells a memory kill from any other exit 137, because a process can end with 137 for another reason.
        return is_array($state) && true === ($state['OOMKilled'] ?? false);
    }

    public function logs(string $containerId): string
    {
        $raw = $this->call('GET', '/containers/'.$containerId.'/logs', ['query' => ['stdout' => 1, 'stderr' => 1, 'tail' => self::LOG_LINES]])->getContent();

        return $this->unframed($raw);
    }

    public function removeContainer(string $containerId): void
    {
        $this->checked($this->request('DELETE', '/containers/'.$containerId, ['query' => ['force' => 1, 'v' => 1]]), 404);
    }

    public function removeNetwork(string $networkId): void
    {
        $this->checked($this->request('DELETE', '/networks/'.$networkId), 404);
    }

    /**
     * @return list<LabeledResource>
     */
    public function jobContainers(): array
    {
        return $this->labeled('/containers/json', ['all' => 1]);
    }

    /**
     * @return list<LabeledResource>
     */
    public function jobNetworks(): array
    {
        return $this->labeled('/networks', []);
    }

    /**
     * @param array<string, int> $query
     *
     * @return list<LabeledResource>
     */
    private function labeled(string $path, array $query): array
    {
        $filters = json_encode(['label' => [JobServices::JOB_LABEL]], JSON_THROW_ON_ERROR);
        $items = $this->call('GET', $path, ['query' => $query + ['filters' => $filters]])->toArray();

        return array_values(array_filter(array_map($this->labeledResource(...), $items)));
    }

    private function labeledResource(mixed $item): ?LabeledResource
    {
        $id = is_array($item) ? ($item['Id'] ?? null) : null;
        $labels = is_array($item) ? ($item['Labels'] ?? null) : null;
        $job = is_array($labels) ? ($labels[JobServices::JOB_LABEL] ?? null) : null;

        return is_string($id) && is_string($job) ? new LabeledResource($id, $job) : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function call(string $method, string $path, array $options = []): ResponseInterface
    {
        return $this->checked($this->request($method, $path, $options));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        return $this->client->request($method, self::BASE.$path, $options);
    }

    // One extra status passes (a container already started, a resource already gone); any other 3xx, 4xx or 5xx throws, because a refused call must never pass as done.
    private function checked(ResponseInterface $response, int $alsoAccepted = 0): ResponseInterface
    {
        if ($alsoAccepted !== $response->getStatusCode()) {
            $response->getContent();
        }

        return $response;
    }

    private function idOf(ResponseInterface $response): string
    {
        $id = $response->toArray()['Id'] ?? null;

        return is_string($id) && '' !== $id ? $id : throw new DockerEngineError('The Docker Engine did not answer a create with an id.');
    }

    // A container without a terminal logs in frames, because the engine puts an 8-byte header before each piece of text.
    private function unframed(string $raw): string
    {
        $text = '';
        $offset = 0;

        while ($offset + 8 <= strlen($raw)) {
            $header = unpack('x4/Nsize', $raw, $offset);
            $size = is_array($header) && is_int($header['size']) ? $header['size'] : 0;
            $text .= substr($raw, $offset + 8, $size);
            $offset += 8 + $size;
        }

        return $text;
    }
}
