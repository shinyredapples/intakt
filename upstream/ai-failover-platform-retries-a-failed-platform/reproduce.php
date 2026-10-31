<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\Failover\FailoverPlatform;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Test\MockModelCatalog;
use Symfony\AI\Platform\Test\MockResultConverter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class CountingPlatform implements PlatformInterface
{
    public int $calls = 0;

    public function __construct(private readonly bool $fails)
    {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        ++$this->calls;

        if ($this->fails) {
            throw new RuntimeException('provider down');
        }

        return new DeferredResult(new MockResultConverter(), new InMemoryRawResult(['text' => 'ok']));
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return new MockModelCatalog();
    }
}

$failing = new CountingPlatform(true);
$working = new CountingPlatform(false);
$limiter = new RateLimiterFactory(['id' => 'failover', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage());
$platform = new FailoverPlatform([$failing, $working], $limiter);

$platform->invoke('model', 'first call');
$platform->invoke('model', 'second call');

echo sprintf('the failing platform was called %d times, the working one %d times', $failing->calls, $working->calls), PHP_EOL;

// The first platform failed and its limiter has no room left for an hour, so the second call should go straight to the working one.
if (1 !== $failing->calls) {
    echo 'a failed platform is called again on the next call', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
