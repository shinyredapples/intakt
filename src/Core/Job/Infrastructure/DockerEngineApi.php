<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobServices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// The only class that knows the Engine API's paths and JSON shapes; it never shells out to a docker command.
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
        $this->call('POST', '/containers/'.$containerId.'/start', [], [304]);
    }

    // Null when the container is still running after the time limit.
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
        $this->call('POST', '/containers/'.$containerId.'/kill', [], [409]);
    }

    public function memoryKilled(string $containerId): bool
    {
        $state = $this->call('GET', '/containers/'.$containerId.'/json')->toArray()['State'] ?? null;

        // Only the engine's own flag tells a memory kill from any other exit 137.
        return is_array($state) && true === ($state['OOMKilled'] ?? false);
    }

    public function logs(string $containerId): string
    {
        $raw = $this->call('GET', '/containers/'.$containerId.'/logs', ['query' => ['stdout' => 1, 'stderr' => 1, 'tail' => self::LOG_LINES]])->getContent();

        return $this->unframed($raw);
    }

    public function removeContainer(string $containerId): void
    {
        $this->call('DELETE', '/containers/'.$containerId, ['query' => ['force' => 1, 'v' => 1]], [404]);
    }

    public function removeNetwork(string $networkId): void
    {
        $this->call('DELETE', '/networks/'.$networkId, [], [404]);
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
        $found = [];

        foreach ($this->call('GET', $path, ['query' => $query + ['filters' => $filters]])->toArray() as $item) {
            $id = is_array($item) ? ($item['Id'] ?? null) : null;
            $job = is_array($item) && is_array($item['Labels'] ?? null) ? ($item['Labels'][JobServices::JOB_LABEL] ?? null) : null;

            if (is_string($id) && is_string($job)) {
                $found[] = new LabeledResource($id, $job);
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<int>            $alsoAccepted
     */
    private function call(string $method, string $path, array $options = [], array $alsoAccepted = []): ResponseInterface
    {
        $response = $this->client->request($method, self::BASE.$path, $options);

        if (!in_array($response->getStatusCode(), $alsoAccepted, true)) {
            // Throws for any 3xx, 4xx or 5xx answer, so a refused call never passes as done.
            $response->getContent();
        }

        return $response;
    }

    private function idOf(ResponseInterface $response): string
    {
        $id = $response->toArray()['Id'] ?? null;

        return is_string($id) && '' !== $id ? $id : throw new DockerEngineError('The Docker Engine did not answer a create with an id.');
    }

    // A container without a terminal logs in frames of an 8-byte header and the text.
    private function unframed(string $raw): string
    {
        $text = '';
        $offset = 0;

        while ($offset + 8 <= strlen($raw)) {
            $size = unpack('N', substr($raw, $offset + 4, 4))[1];
            $text .= substr($raw, $offset + 8, $size);
            $offset += 8 + $size;
        }

        return $text;
    }
}
