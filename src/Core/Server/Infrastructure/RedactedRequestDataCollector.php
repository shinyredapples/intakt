<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\VarDumper\Cloner\Data;

// The request's server bag also carries the whole environment (APP_SECRET, provider keys, the owner token), and a saved profile is a file that outlives the request, so only what the request itself sent keeps its value.
// Excluded from the scan of src/, because autoconfiguration would add it as a second request collector; RedactRequestProfilesPass installs it in place of FrameworkBundle's.
#[Exclude]
final class RedactedRequestDataCollector extends RequestDataCollector
{
    public const string MASK = '******';

    // An allow-list, because a new environment variable must stay hidden without anyone adding it here.
    private const string REQUEST_VARIABLE = '/^(?:HTTP_(?!AUTHORIZATION$)|REQUEST_|SERVER_|REMOTE_|SCRIPT_|CONTENT_|REDIRECT_STATUS$|PATH_INFO$|ORIG_PATH_INFO$|PATH_TRANSLATED$|QUERY_STRING$|DOCUMENT_ROOT$|DOCUMENT_URI$|PHP_SELF$|PHP_AUTH_USER$|GATEWAY_INTERFACE$|HTTPS$)/';

    // The parent freezes its data here once lateCollect has added the curl command, so this is the one place that sees every copy of a value.
    protected function cloneVar(mixed $var): Data
    {
        return parent::cloneVar(is_array($var) ? $this->redacted($var) : $var);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function redacted(array $data): array
    {
        $credential = is_array($data['request_headers'] ?? null) ? $data['request_headers']['authorization'] ?? null : null;

        if (is_array($data['request_server'] ?? null)) {
            $data['request_server'] = $this->withEnvironmentMasked($data['request_server']);
        }

        if (is_array($data['dotenv_vars'] ?? null)) {
            $data['dotenv_vars'] = array_map(static fn (): string => self::MASK, $data['dotenv_vars']);
        }

        if (!is_string($credential) || '' === $credential) {
            return $data;
        }

        $data['request_headers']['authorization'] = self::MASK;

        if (is_string($data['curlCommand'] ?? null)) {
            $data['curlCommand'] = str_replace($credential, self::MASK, $data['curlCommand']);
        }

        return $data;
    }

    /**
     * @param array<mixed> $server
     *
     * @return array<mixed>
     */
    private function withEnvironmentMasked(array $server): array
    {
        foreach (array_keys($server) as $name) {
            if (1 !== preg_match(self::REQUEST_VARIABLE, (string) $name)) {
                $server[$name] = self::MASK;
            }
        }

        return $server;
    }
}
