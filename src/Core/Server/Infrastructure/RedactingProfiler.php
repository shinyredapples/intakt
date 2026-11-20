<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
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

    private const array PARTS = ['request_server', 'dotenv_vars', 'request_headers'];

    // Shorter values (a debug flag, an environment name) would blot out ordinary text of a trace.
    private const int SHORTEST_SCRUBBED = 8;

    // PHP's getTraceAsString() keeps this many leading bytes of a string argument.
    private const int TRACE_STRING_PREFIX = 15;

    public function collect(Request $request, Response $response, ?Throwable $exception = null): ?Profile
    {
        $profile = parent::collect($request, $response, $exception);

        if (!$profile instanceof Profile || !$profile->hasCollector('request')) {
            return $profile;
        }

        $collected = $profile->getCollector('request');
        $data = $collected instanceof RequestDataCollector ? $collected->__serialize()['data'] ?? null : null;

        if (!is_array($data)) {
            return $profile;
        }

        $secrets = $this->secretsOf($data);
        $profile->addCollector($this->masked($data));

        // With zend.exception_ignore_args off, the frames of a failed request's exception carry the server array as an argument.
        $failure = $profile->hasCollector('exception') ? $profile->getCollector('exception') : null;

        if ($failure instanceof DataCollector) {
            $this->scrub($failure, $secrets);
        }

        return $profile;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private function secretsOf(array $data): array
    {
        $secrets = [];

        foreach (self::PARTS as $part) {
            $values = $data[$part] ?? null;
            $secrets = [...$secrets, ...array_values(array_filter(is_array($values) ? $this->secretsIn($values, $part) : [], is_string(...)))];
        }

        return $secrets;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private function secretsIn(array $values, string $part): array
    {
        return array_filter($values, fn (int|string $name): bool => $this->isSecret($part, (string) $name), ARRAY_FILTER_USE_KEY);
    }

    // A fresh collector holds no live request, so the curl command it would build from the request's headers (the Authorization header among them) is never made.
    /**
     * @param array<mixed> $data
     */
    private function masked(array $data): RequestDataCollector
    {
        foreach (self::PARTS as $part) {
            $values = $data[$part] ?? null;

            if (is_array($values)) {
                $data[$part] = array_replace($values, array_map(static fn (): string => self::MASK, $this->secretsIn($values, $part)));
            }
        }

        $masked = new RequestDataCollector();
        $masked->__unserialize(['data' => $data]);

        return $masked;
    }

    // Each value is replaced by as many asterisks as it has bytes, so the serialized data keeps every length it declares.
    /**
     * @param list<string> $secrets
     */
    private function scrub(DataCollector $collector, array $secrets): void
    {
        $replacements = [];

        foreach ($secrets as $secret) {
            if (strlen($secret) >= self::SHORTEST_SCRUBBED) {
                $replacements[$secret] = str_repeat('*', strlen($secret));
                $prefix = substr($secret, 0, self::TRACE_STRING_PREFIX);
                $replacements[$prefix] = str_repeat('*', strlen($prefix));
            }
        }

        $serialized = serialize($collector->__serialize()['data'] ?? null);
        $scrubbed = strtr($serialized, $replacements);

        if ($scrubbed !== $serialized) {
            $collector->__unserialize(['data' => unserialize($scrubbed)]);
        }
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
