<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Throwable;

// The request's server bag carries the whole environment (APP_SECRET, provider keys, the owner token) and a saved profile outlives the request, so only what the request itself sent keeps its value; excluded from the scan of src/, because RedactRequestProfilesPass installs it only where FrameworkBundle defines a profiler.
#[Exclude]
final class RedactingProfiler extends Profiler
{
    public const string MASK = '******';

    // An allow-list, because a new environment variable must stay hidden without anyone adding it here.
    private const string REQUEST_VARIABLE = '/^(?:HTTP_(?!AUTHORIZATION$)|REQUEST_|SERVER_|REMOTE_|SCRIPT_|CONTENT_|REDIRECT_STATUS$|PATH_INFO$|ORIG_PATH_INFO$|PATH_TRANSLATED$|QUERY_STRING$|DOCUMENT_ROOT$|DOCUMENT_URI$|PHP_SELF$|PHP_AUTH_USER$|GATEWAY_INTERFACE$|HTTPS$)/';

    public function collect(Request $request, Response $response, ?Throwable $exception = null): ?Profile
    {
        $profile = parent::collect($request, $response, $exception);

        if ($profile instanceof Profile && $profile->hasCollector('request')) {
            $collected = $profile->getCollector('request');

            if ($collected instanceof RequestDataCollector) {
                $profile->addCollector($this->masked($collected));
            }
        }

        return $profile;
    }

    // A fresh collector holds no live request, so the curl command it would build from the request's headers (the Authorization header among them) is never made.
    private function masked(RequestDataCollector $collected): RequestDataCollector
    {
        $data = $collected->__serialize()['data'] ?? null;
        $masked = new RequestDataCollector();

        if (!is_array($data)) {
            return $masked;
        }

        foreach (['request_server', 'dotenv_vars', 'request_headers'] as $part) {
            $values = $data[$part] ?? null;

            if (is_array($values)) {
                $data[$part] = $this->withSecretsMasked($part, $values);
            }
        }

        $masked->__unserialize(['data' => $data]);

        return $masked;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private function withSecretsMasked(string $part, array $values): array
    {
        foreach (array_keys($values) as $name) {
            if ($this->isSecret($part, (string) $name)) {
                $values[$name] = self::MASK;
            }
        }

        return $values;
    }

    private function isSecret(string $part, string $name): bool
    {
        return match ($part) {
            'request_server' => 1 !== preg_match(self::REQUEST_VARIABLE, $name),
            'request_headers' => 'authorization' === strtolower($name),
            default => true,
        };
    }
}
