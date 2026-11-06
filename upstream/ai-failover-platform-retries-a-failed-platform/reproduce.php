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
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class FailingPlatform implements PlatformInterface
{
    public int $calls = 0;

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        ++$this->calls;

        throw new RuntimeException('provider down');
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return new MockModelCatalog();
    }
}

final class WorkingPlatform implements PlatformInterface
{
    public int $calls = 0;

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        ++$this->calls;

        return new DeferredResult(new MockResultConverter(), new InMemoryRawResult(['text' => 'ok']));
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return new MockModelCatalog();
    }
}

final class KeyRecordingLimiterFactory implements RateLimiterFactoryInterface
{
    /** @var list<string|null> */
    public array $keys = [];

    public function __construct(private readonly RateLimiterFactoryInterface $factory)
    {
    }

    public function create(?string $key = null): LimiterInterface
    {
        $this->keys[] = $key;

        return $this->factory->create($key);
    }
}

function oneTokenPerHour(): RateLimiterFactory
{
    return new RateLimiterFactory(['id' => 'failover', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage());
}

$passes = true;

// Two classes, so each platform has a limiter of its own and the retry shows apart from the shared key.
$failing = new FailingPlatform();
$working = new WorkingPlatform();
$platform = new FailoverPlatform([$failing, $working], oneTokenPerHour());

$platform->invoke('model', 'first call');
$platform->invoke('model', 'second call');

echo sprintf('retry: the failing platform was called %d times, the working one %d times', $failing->calls, $working->calls), PHP_EOL;

// The failing platform's own limiter has no room left for an hour, so the second call should go straight to the working one.
if (1 !== $failing->calls) {
    echo 'a failed platform is called again on the next call', PHP_EOL;
    $passes = false;
}

$limiters = new KeyRecordingLimiterFactory(oneTokenPerHour());
$platform = new FailoverPlatform([new FailingPlatform(), new FailingPlatform(), new WorkingPlatform()], $limiters);
$platform->invoke('model', 'a call');

echo sprintf('limiter keys of two failing platforms and a working one: %s', implode(', ', $limiters->keys)), PHP_EOL;

// Two accounts of one provider are two platforms of one class; each needs a limiter of its own.
if (count(array_unique($limiters->keys)) !== count($limiters->keys)) {
    echo 'two platforms of one class share one limiter', PHP_EOL;
    $passes = false;
}

if (!$passes) {
    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
