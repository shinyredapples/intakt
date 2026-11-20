<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\HttpClient\DataCollector\HttpClientDataCollector;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;

// Only these two collectors take credentials in; every other one can only have copied a value from them, so RedactingProfilerStorage scrubs their values everywhere.
final readonly class ProfileCredentials
{
    public function __construct(
        private RequestCredentials $request,
        private CallCredentials $calls,
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
            ...$request instanceof RequestDataCollector ? $this->request->of($request) : [],
            ...$calls instanceof HttpClientDataCollector ? $this->calls->of($calls) : [],
        ];
    }
}
