<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\ProfilerStorageInterface;
use Uri\Rfc3986\Uri;

// Because a query string can carry a credential and the profiler saves the full URL and client address outside the collector allow-list (docs/architecture/development-diagnostics.md).
#[AsDecorator('profiler.storage', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final readonly class PathOnlyProfilerStorage implements ProfilerStorageInterface
{
    public function __construct(
        #[AutowireDecorated]
        private ProfilerStorageInterface $storage,
    ) {
    }

    public function write(Profile $profile): bool
    {
        $profile->setUrl($this->withoutQueryAndFragment($profile->getUrl()));
        $profile->setIp(null);

        return $this->storage->write($profile);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function find(?string $ip, ?string $url, ?int $limit, ?string $method, ?int $start = null, ?int $end = null, ?string $statusCode = null, ?Closure $filter = null): array
    {
        return $this->storage->find($ip, $url, $limit, $method, $start, $end, $statusCode, $filter);
    }

    public function read(string $token): ?Profile
    {
        return $this->storage->read($token);
    }

    public function purge(): void
    {
        $this->storage->purge();
    }

    // An unreadable URL is dropped, not kept raw, because its query could not be found and removed.
    private function withoutQueryAndFragment(?string $url): ?string
    {
        if (null === $url) {
            return null;
        }

        return Uri::parse($url)?->withQuery(null)->withFragment(null)->toRawString();
    }
}
