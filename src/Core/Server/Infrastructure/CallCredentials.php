<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\HttpClient\DataCollector\HttpClientDataCollector;

// The HTTP client collector keeps each call's options, curl command and debug log, so a provider key or forge token Intakt sent is a credential of the profile too.
final readonly class CallCredentials
{
    public function __construct(
        private CredentialValues $values,
    ) {
    }

    /**
     * @return list<string>
     */
    public function of(HttpClientDataCollector $calls): array
    {
        $traces = array_merge([], ...array_map(fn (mixed $client): array => $this->values->rawArray(is_array($client) ? $client['traces'] ?? [] : []), array_values($calls->getClients())));

        return array_merge([], ...array_map(fn (mixed $trace): array => $this->ofTheOptions($this->values->rawArray(is_array($trace) ? $trace['options'] ?? [] : [])), array_values($traces)));
    }

    /**
     * @param array<mixed> $options
     *
     * @return list<string>
     */
    private function ofTheOptions(array $options): array
    {
        return [
            ...$this->values->strings([$options['auth_bearer'] ?? null]),
            ...$this->ofTheBasicAuth($options['auth_basic'] ?? null),
            ...$this->values->inHeaders($this->headerMap($this->values->rawArray($options['headers'] ?? []))),
            ...$this->values->inFields($this->values->rawArray($options['query'] ?? [])),
            ...$this->values->inFields($this->values->rawArray($options['body'] ?? [])),
            ...$this->values->inFields($this->values->rawArray($options['json'] ?? [])),
        ];
    }

    // The base64 form too, because the call's own Authorization header carries only that.
    /**
     * @return list<string>
     */
    private function ofTheBasicAuth(mixed $basic): array
    {
        $pair = implode(':', $this->values->strings($basic));

        return '' === $pair ? [] : [$pair, base64_encode($pair), substr($pair, (int) strpos($pair, ':') + 1)];
    }

    // Normalized, because a call may give its headers as a list of "Name: value" lines instead of a map.
    /**
     * @param array<mixed> $headers
     *
     * @return array<mixed>
     */
    private function headerMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $name => $value) {
            [$name, $value] = is_int($name) && is_string($value) && str_contains($value, ':') ? array_map(trim(...), explode(':', $value, 2)) : [$name, $value];
            $map[$name] = $value;
        }

        return $map;
    }
}
