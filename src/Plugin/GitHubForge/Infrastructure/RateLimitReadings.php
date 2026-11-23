<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

// In a cache every worker and the scheduler share, so all processes of one installation see its latest budget and its pause.
final readonly class RateLimitReadings
{
    private const array LIMITED_STATUSES = [403, 429];

    // Written out, because PHP 8.5 deprecates DATE_RFC7231; HTTP dates are always in GMT.
    private const string HTTP_DATE = '!D, d M Y H:i:s \G\M\T';

    // One minute because GitHub named no reset still ahead, which is also the shortest wait its secondary limits ask for.
    private const int SECONDS_WITHOUT_RESET = 60;

    // GitHub counts REST calls and GraphQL queries in separate pools, so each pool keeps its own pause and only `core` feeds the reserve.
    public const string CORE = 'core';
    public const string GRAPHQL = 'graphql';

    public function __construct(
        #[Autowire(service: GitHubForgeServices::RATE_LIMITS)]
        private CacheItemPoolInterface $pool,
        private ClockInterface $clock,
        private LockFactory $locks,
    ) {
    }

    /**
     * @throws ForgeRateLimited
     */
    public function refuseWhileLimited(string $installationId, string $pool): void
    {
        $until = $this->storedPause($installationId, $pool);

        // Compared with the clock because the cache expiry runs on system time.
        if ($until instanceof DateTimeImmutable && $until > $this->clock->now()) {
            throw new ForgeRateLimited($until);
        }
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @throws ForgeRateLimited
     */
    public function observe(string $installationId, int $status, array $headers): void
    {
        $pool = $headers['x-ratelimit-resource'][0] ?? self::CORE;

        $reading = $this->readingOf($headers);
        if (self::CORE === $pool && $reading instanceof RateLimitReading) {
            $this->remember($installationId, $reading);
        }

        $until = $this->limitedUntil($pool, $status, $headers);
        if ($until instanceof DateTimeImmutable) {
            throw new ForgeRateLimited($this->pause($installationId, $pool, $until));
        }
    }

    public function latest(string $installationId): ?RateLimitReading
    {
        $item = $this->pool->getItem($this->key('reading', $installationId, self::CORE));
        $value = $item->get();

        if (!$item->isHit() || !is_array($value) || !is_int($value['remaining'] ?? null) || !is_int($value['limit'] ?? null) || !is_int($value['reset'] ?? null)) {
            return null;
        }

        $resetsAt = $this->at($value['reset']);

        return $resetsAt > $this->clock->now() ? new RateLimitReading($value['remaining'], $value['limit'], $resetsAt) : null;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function readingOf(array $headers): ?RateLimitReading
    {
        $remaining = $this->number($headers, 'x-ratelimit-remaining');
        $limit = $this->number($headers, 'x-ratelimit-limit');
        $reset = $this->number($headers, 'x-ratelimit-reset');

        // Only whole, because the reserve is a share of the limit and a reading without one could not hold it.
        return in_array(null, [$remaining, $limit, $reset], true) ? null : new RateLimitReading($remaining, $limit, $this->at($reset));
    }

    private function remember(string $installationId, RateLimitReading $reading): void
    {
        $resetsAt = $reading->resetsAt;
        if ($resetsAt <= $this->clock->now()) {
            return;
        }

        $value = ['remaining' => $reading->remaining, 'limit' => $reading->limit, 'reset' => $resetsAt->getTimestamp()];
        $item = $this->pool->getItem($this->key('reading', $installationId, self::CORE))->set($value)->expiresAt($resetsAt);
        $this->pool->save($item);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function limitedUntil(string $pool, int $status, array $headers): ?DateTimeImmutable
    {
        // Any other 403 stays an ordinary refusal, because a missing permission answers it too. GitHub answers an exhausted GraphQL pool with 200 and a RATE_LIMITED error, so there an empty budget counts at any status; an answer that spent the last point is read again after the reset, because its headers do not tell it apart from the refusal.
        $limitedStatus = in_array($status, self::LIMITED_STATUSES, true);
        if (!$limitedStatus && self::GRAPHQL !== $pool) {
            return null;
        }

        if ($limitedStatus && isset($headers['retry-after'])) {
            return $this->ahead($this->retryAfter($headers['retry-after'][0] ?? ''));
        }

        if (0 !== $this->number($headers, 'x-ratelimit-remaining')) {
            return null;
        }

        $reset = $this->number($headers, 'x-ratelimit-reset');

        return $this->ahead(null === $reset ? null : $this->at($reset));
    }

    // Both forms, because HTTP allows Retry-After as seconds or as a date (RFC 9110, section 10.2.3).
    private function retryAfter(string $value): ?DateTimeImmutable
    {
        if (ctype_digit($value) && strlen($value) < 12) {
            return $this->clock->now()->modify(sprintf('+%d seconds', (int) $value));
        }

        $moment = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $value, new DateTimeZone('UTC'));

        return false === $moment || false !== DateTimeImmutable::getLastErrors() ? null : $moment;
    }

    // A reset that has already passed (a skewed clock) counts as none, because waiting on it would send the next call at once.
    private function ahead(?DateTimeImmutable $moment): DateTimeImmutable
    {
        $now = $this->clock->now();

        return $moment instanceof DateTimeImmutable && $moment > $now ? $moment : $now->modify(sprintf('+%d seconds', self::SECONDS_WITHOUT_RESET));
    }

    private function pause(string $installationId, string $pool, DateTimeImmutable $until): DateTimeImmutable
    {
        // Under the lock every process takes, because two answers in flight could otherwise replace a longer pause with a shorter one.
        $lock = $this->locks->createLock($this->key('pause', $installationId, $pool));
        $lock->acquire(true);

        try {
            $longest = max($until, $this->storedPause($installationId, $pool) ?? $until);
            $this->pool->save($this->pool->getItem($this->key('limited', $installationId, $pool))->set($longest->getTimestamp())->expiresAt($longest));

            return $longest;
        } finally {
            $lock->release();
        }
    }

    private function storedPause(string $installationId, string $pool): ?DateTimeImmutable
    {
        $current = $this->pausedUntil($this->pool->getItem($this->key('limited', $installationId, $pool)));
        $earlier = $this->pauseStoredByAnEarlierVersion($installationId, $pool);

        return $current instanceof DateTimeImmutable && $earlier instanceof DateTimeImmutable ? max($current, $earlier) : $current ?? $earlier;
    }

    private function pausedUntil(CacheItemInterface $item): ?DateTimeImmutable
    {
        $value = $item->get();

        return $item->isHit() && is_int($value) ? $this->at($value) : null;
    }

    // Honored until it expires, because an install upgraded during a pause stored it under the key the earlier version used (the installation id alone, for REST calls only), and dropping it would resume calls before GitHub's reset.
    private function pauseStoredByAnEarlierVersion(string $installationId, string $pool): ?DateTimeImmutable
    {
        if (self::CORE !== $pool) {
            return null;
        }

        return $this->pausedUntil($this->pool->getItem('github-rate-limit-limited-'.hash('sha256', $installationId)));
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function number(array $headers, string $name): ?int
    {
        $value = $headers[$name][0] ?? null;

        return is_string($value) && ctype_digit($value) && strlen($value) < 12 ? (int) $value : null;
    }

    // Hashed, because the id comes from the owner's settings, the pool from GitHub's answer, and a cache key allows only a narrow set of characters.
    private function key(string $kind, string $installationId, string $pool): string
    {
        return 'github-rate-limit-'.$kind.'-'.hash('sha256', strlen($pool).':'.$pool.$installationId);
    }

    private function at(int $epoch): DateTimeImmutable
    {
        return $this->clock->now()->setTimestamp($epoch);
    }
}
