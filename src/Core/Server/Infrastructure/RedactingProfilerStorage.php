<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Closure;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\DataCollector\DataCollectorInterface;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\ProfilerStorageInterface;

// A saved profile is a file that outlives the request, so every credential it copied (environment, headers, cookies, posted keys, outgoing calls' auth) is scrubbed by value from every collector before the write; the store is decorated because write() runs after each late collect, and only where FrameworkBundle defines a profiler (prod has none).
#[AsDecorator('profiler.storage', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final readonly class RedactingProfilerStorage implements ProfilerStorageInterface
{
    // Shorter values (a debug flag, an environment name) would blot out ordinary text.
    private const int SHORTEST_SCRUBBED = 8;

    // PHP's getTraceAsString() keeps this many leading bytes of a string argument.
    private const int TRACE_STRING_PREFIX = 15;

    private const string TRACES = 'exception';

    public function __construct(
        private ProfilerStorageInterface $inner,
        private ProfileCredentials $credentials,
    ) {
    }

    #[Override]
    public function find(?string $ip, ?string $url, ?int $limit, ?string $method, ?int $start = null, ?int $end = null, ?string $statusCode = null, ?Closure $filter = null): array
    {
        return $this->inner->find($ip, $url, $limit, $method, $start, $end, $statusCode, $filter);
    }

    #[Override]
    public function read(string $token): ?Profile
    {
        return $this->inner->read($token);
    }

    #[Override]
    public function write(Profile $profile): bool
    {
        $this->scrub($profile, $this->credentials->in($profile));

        return $this->inner->write($profile);
    }

    #[Override]
    public function purge(): void
    {
        $this->inner->purge();
    }

    /**
     * @param list<string> $credentials
     */
    private function scrub(Profile $profile, array $credentials): void
    {
        $spellings = $this->replacements($credentials, $this->spellingsOf(...));

        if ([] === $spellings) {
            return;
        }

        $traced = $this->replacements($credentials, fn (string $credential): array => [...$this->spellingsOf($credential), substr($credential, 0, self::TRACE_STRING_PREFIX)]);
        $profile->setUrl(strtr((string) $profile->getUrl(), $spellings));
        $scrubbed = [];

        foreach ($profile->getCollectors() as $name => $collector) {
            $copy = $this->scrubbed($collector, self::TRACES === $name ? $traced : $spellings);

            if ($copy instanceof DataCollectorInterface) {
                $scrubbed[] = $copy;
            }
        }

        $profile->setCollectors($scrubbed);
    }

    // Each spelling is replaced by as many asterisks as it has bytes, so the serialized data keeps every length it declares; a collector that does not come back whole is dropped rather than saved with a credential.
    /**
     * @param array<string, string> $replacements
     */
    private function scrubbed(DataCollectorInterface $collector, array $replacements): ?DataCollectorInterface
    {
        $serialized = serialize($collector);
        $scrubbed = strtr($serialized, $replacements);

        if ($scrubbed === $serialized) {
            return $collector;
        }

        $copy = unserialize($scrubbed);

        return $copy instanceof DataCollectorInterface ? $copy : null;
    }

    /**
     * @param list<string>                    $credentials
     * @param Closure(string): list<string> $spellings
     *
     * @return array<string, string>
     */
    private function replacements(array $credentials, Closure $spellings): array
    {
        $replacements = [];

        foreach ($credentials as $credential) {
            foreach ($spellings($credential) as $spelling) {
                if (strlen($credential) >= self::SHORTEST_SCRUBBED && strlen($spelling) >= self::SHORTEST_SCRUBBED) {
                    $replacements[$spelling] = str_repeat('*', strlen($spelling));
                }
            }
        }

        return $replacements;
    }

    // As a request body, a curl command and a JSON dump write it.
    /**
     * @return list<string>
     */
    private function spellingsOf(string $credential): array
    {
        $json = json_encode($credential, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $bareJson = json_encode($credential, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [$credential, rawurlencode($credential), urlencode($credential), substr($json, 1, -1), substr($bareJson, 1, -1)];
    }
}
