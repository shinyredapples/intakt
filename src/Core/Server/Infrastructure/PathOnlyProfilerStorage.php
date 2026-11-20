<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\ProfilerStorageInterface;

// The profiler writes the request's full URL and client address beside the collectors, outside their allow-list, and a query string can carry a credential; dropped wherever the profiler is defined, and gone with it in prod.
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
        $profile->setUrl(explode('?', $profile->getUrl() ?? '', 2)[0]);
        $profile->setIp(null);

        return $this->storage->write($profile);
    }

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
}
