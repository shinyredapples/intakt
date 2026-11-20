<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;

// The request's server bag carries the whole environment (APP_SECRET, provider keys, the owner token) besides what the client sent, so its values count as credentials unless the variable is known to be safe.
final readonly class RequestCredentials
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

    // Never kept, because it is the environment's proxy rather than a header the client sent (httpoxy), and a proxy URL can hold a password.
    private const string PROXY_VARIABLE = 'HTTP_PROXY';

    public function __construct(
        private CredentialValues $values,
    ) {
    }

    /**
     * @return list<string>
     */
    public function of(RequestDataCollector $request): array
    {
        return [
            ...$this->environmentIn($this->values->rawArray($request->getRequestServer(raw: true)->all())),
            ...$this->values->strings($this->values->raw($request->getDotenvVars()->all())),
            ...$this->values->inHeaders($this->values->rawArray($request->getRequestHeaders()->all())),
            ...$this->values->strings($this->values->raw($request->getRequestCookies(raw: true)->all())),
            ...$this->responseCookiesIn($this->values->rawArray($request->getResponseHeaders()->all())),
            ...$this->values->inFields($this->values->rawArray($request->getRequestRequest()->all())),
            ...$this->values->inFields($this->values->rawArray($request->getRequestQuery()->all())),
        ];
    }

    /**
     * @param array<mixed> $server
     *
     * @return list<string>
     */
    private function environmentIn(array $server): array
    {
        return $this->values->strings(array_filter($server, fn (int|string $name): bool => !$this->isRequestVariable((string) $name), ARRAY_FILTER_USE_KEY));
    }

    private function isRequestVariable(string $name): bool
    {
        if (in_array($name, [...self::REQUEST_VARIABLES, ...self::PROCESS_VARIABLES], true)) {
            return true;
        }

        return str_starts_with($name, 'HTTP_') && self::PROXY_VARIABLE !== $name && !$this->values->isCredentialHeader(str_replace('_', '-', substr($name, 5)));
    }

    /**
     * @param array<mixed> $headers
     *
     * @return list<string>
     */
    private function responseCookiesIn(array $headers): array
    {
        $values = [];

        foreach ($this->values->strings([$headers['set-cookie'] ?? null]) as $line) {
            $value = explode(';', explode('=', $line, 2)[1] ?? '', 2)[0];
            $values = [...$values, $value, rawurldecode($value)];
        }

        return $values;
    }
}
