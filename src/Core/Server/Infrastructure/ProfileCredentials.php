<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\AutowireInline;
use Symfony\Component\HttpClient\DataCollector\HttpClientDataCollector;
use Symfony\Component\HttpClient\Recorder\Redactor\DefaultRedactor;
use Symfony\Component\HttpClient\Recorder\Redactor\RedactorInterface;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\VarDumper\Cloner\Data;

// Collects the values of a profile that are credentials, so RedactingProfilerStorage can scrub them from every collector that copied one; the names come from HttpClient's DefaultRedactor, so its list of credential headers and fields has one home.
final readonly class ProfileCredentials
{
    // An allow-list of what a request says about itself, because a new environment variable must stay hidden without anyone adding it here; an HTTP_ variable is judged by its header name instead.
    private const array REQUEST_VARIABLES = [
        'REQUEST_METHOD', 'REQUEST_URI', 'REQUEST_SCHEME', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT', 'QUERY_STRING',
        'SERVER_NAME', 'SERVER_PORT', 'SERVER_PROTOCOL', 'SERVER_SOFTWARE', 'REMOTE_ADDR', 'REMOTE_PORT',
        'SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF', 'PATH_INFO', 'DOCUMENT_ROOT', 'DOCUMENT_URI',
        'CONTENT_TYPE', 'CONTENT_LENGTH', 'HTTPS', 'GATEWAY_INTERFACE', 'REDIRECT_STATUS',
    ];

    // Kept as well, because they name no credential and the project path in PWD would otherwise blot every file path out of the profile.
    private const array PROCESS_VARIABLES = ['PATH', 'PWD', 'HOME', 'SHELL', 'USER', 'LANG', 'APP_ENV', 'APP_DEBUG', 'SYMFONY_DOTENV_VARS', 'SYMFONY_DOTENV_PATH'];

    // The environment's proxy, not a header the client sent (httpoxy), and a proxy URL can hold a password.
    private const string PROXY_VARIABLE = 'HTTP_PROXY';

    private const string PROBE = 'probe';

    public function __construct(
        #[AutowireInline(DefaultRedactor::class)]
        private RedactorInterface $names,
    ) {
    }

    /**
     * @return list<string>
     */
    public function in(Profile $profile): array
    {
        $request = $profile->hasCollector('request') ? $profile->getCollector('request') : null;
        $calls = $profile->hasCollector('http_client') ? $profile->getCollector('http_client') : null;

        return [
            ...$request instanceof RequestDataCollector ? $this->ofTheRequest($request) : [],
            ...$calls instanceof HttpClientDataCollector ? $this->ofTheCalls($calls) : [],
        ];
    }

    /**
     * @return list<string>
     */
    private function ofTheRequest(RequestDataCollector $request): array
    {
        return [
            ...$this->environmentIn($this->plain($request->getRequestServer(raw: true)->all())),
            ...$this->strings($this->plain($request->getDotenvVars()->all())),
            ...$this->credentialHeadersIn($this->plain($request->getRequestHeaders()->all())),
            ...$this->strings($this->plain($request->getRequestCookies(raw: true)->all())),
            ...$this->responseCookiesIn($this->plain($request->getResponseHeaders()->all())),
            ...$this->credentialFieldsIn($this->plain($request->getRequestRequest()->all())),
            ...$this->credentialFieldsIn($this->plain($request->getRequestQuery()->all())),
        ];
    }

    /**
     * @return list<string>
     */
    private function ofTheCalls(HttpClientDataCollector $calls): array
    {
        $credentials = [];

        foreach ($calls->getClients() as $client) {
            foreach (is_array($client) && is_array($client['traces'] ?? null) ? $client['traces'] : [] as $trace) {
                $options = is_array($trace) ? $this->unwrap($trace['options'] ?? []) : [];
                $credentials = [...$credentials, ...$this->ofTheOptions(is_array($options) ? $options : [])];
            }
        }

        return $credentials;
    }

    /**
     * @param array<mixed> $options
     *
     * @return list<string>
     */
    private function ofTheOptions(array $options): array
    {
        $basic = $options['auth_basic'] ?? null;
        $pair = is_array($basic) ? implode(':', $this->strings($basic)) : (is_string($basic) ? $basic : '');

        return [
            ...$this->strings([$options['auth_bearer'] ?? null]),
            ...('' === $pair ? [] : [$pair, base64_encode($pair), substr($pair, (int) strpos($pair, ':') + 1)]),
            ...$this->credentialHeadersIn($this->headerMap(is_array($options['headers'] ?? null) ? $options['headers'] : [])),
            ...$this->credentialFieldsIn(is_array($options['query'] ?? null) ? $options['query'] : []),
            ...$this->credentialFieldsIn(is_array($options['body'] ?? null) ? $options['body'] : []),
            ...$this->credentialFieldsIn(is_array($options['json'] ?? null) ? $options['json'] : []),
        ];
    }

    /**
     * @param array<mixed> $server
     *
     * @return list<string>
     */
    private function environmentIn(array $server): array
    {
        return $this->strings(array_filter($server, fn (int|string $name): bool => !$this->isRequestVariable((string) $name), ARRAY_FILTER_USE_KEY));
    }

    private function isRequestVariable(string $name): bool
    {
        if (in_array($name, [...self::REQUEST_VARIABLES, ...self::PROCESS_VARIABLES], true)) {
            return true;
        }

        return str_starts_with($name, 'HTTP_') && self::PROXY_VARIABLE !== $name && !$this->isCredentialHeader(str_replace('_', '-', substr($name, 5)));
    }

    /**
     * @param array<mixed> $headers
     *
     * @return list<string>
     */
    private function credentialHeadersIn(array $headers): array
    {
        $credentials = [];

        foreach ($headers as $name => $values) {
            if ($this->isCredentialHeader((string) $name)) {
                foreach ($this->strings([$values]) as $value) {
                    // The part after the scheme too, because a firewall keeps the bare token of "Bearer <token>".
                    $credentials = [...$credentials, $value, ...(1 === preg_match('/^\S+\s+(\S.*)$/', $value, $token) ? [$token[1]] : [])];
                }
            }
        }

        return $credentials;
    }

    private function isCredentialHeader(string $name): bool
    {
        return [self::PROBE] !== ($this->names->redactHeaders([$name => self::PROBE])[$name] ?? null);
    }

    /**
     * @param array<mixed> $headers
     *
     * @return list<string>
     */
    private function responseCookiesIn(array $headers): array
    {
        $values = [];

        foreach ($this->strings([$headers['set-cookie'] ?? null]) as $line) {
            $value = explode(';', explode('=', $line, 2)[1] ?? '', 2)[0];
            $values = [...$values, $value, rawurldecode($value)];
        }

        return $values;
    }

    // Through the redactor's own form masking, so a nested name such as user[password] counts as it does there.
    /**
     * @param array<mixed> $fields
     *
     * @return list<string>
     */
    private function credentialFieldsIn(array $fields): array
    {
        $pairs = explode('&', http_build_query($fields, '', '&', PHP_QUERY_RFC3986));
        $masked = explode('&', (string) $this->names->redactBody(implode('&', $pairs)));
        $values = [];

        foreach ($pairs as $index => $pair) {
            if (($masked[$index] ?? $pair) !== $pair) {
                $values[] = rawurldecode(explode('=', $pair, 2)[1] ?? '');
            }
        }

        return $values;
    }

    // An outgoing call may give its headers as a list of "Name: value" lines instead of a map.
    /**
     * @param array<mixed> $headers
     *
     * @return array<mixed>
     */
    private function headerMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $name => $value) {
            if (is_int($name) && is_string($value) && str_contains($value, ':')) {
                [$name, $value] = array_map(trim(...), explode(':', $value, 2));
            }

            $map[$name] = $value;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (is_array($value)) {
            return array_merge([], ...array_map($this->strings(...), array_values($value)));
        }

        return is_string($value) ? [$value] : [];
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private function plain(array $values): array
    {
        return array_map($this->unwrap(...), $values);
    }

    // A collector that already ran its late collect holds VarDumper data instead of plain arrays.
    private function unwrap(mixed $value): mixed
    {
        if ($value instanceof Data) {
            return $value->getValue(true);
        }

        return is_array($value) ? array_map($this->unwrap(...), $value) : $value;
    }
}
