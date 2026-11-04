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
    public function refuseWhileLimited(string $installationId): void
    {
        $until = $this->pausedUntil($this->pool->getItem($this->key('limited', $installationId)));

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
        $remaining = $this->number($headers, 'x-ratelimit-remaining');
        $reset = $this->number($headers, 'x-ratelimit-reset');
        if (null !== $remaining && null !== $reset) {
            $this->remember($installationId, $remaining, $reset);
        }

        $until = $this->limitedUntil($status, $headers);
        if ($until instanceof DateTimeImmutable) {
            throw new ForgeRateLimited($this->pause($installationId, $until));
        }
    }

    public function latest(string $installationId): ?RateLimitReading
    {
        $item = $this->pool->getItem($this->key('reading', $installationId));
        $value = $item->get();

        if (!$item->isHit() || !is_array($value) || !is_int($value['remaining'] ?? null) || !is_int($value['reset'] ?? null)) {
            return null;
        }

        $resetsAt = $this->at($value['reset']);

        return $resetsAt > $this->clock->now() ? new RateLimitReading($value['remaining'], $resetsAt) : null;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function limitedUntil(int $status, array $headers): ?DateTimeImmutable
    {
        // Any other 403 stays an ordinary refusal, because a missing permission answers it too.
        if (!in_array($status, self::LIMITED_STATUSES, true)) {
            return null;
        }

        if (isset($headers['retry-after'])) {
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

    private function remember(string $installationId, int $remaining, int $reset): void
    {
        $resetsAt = $this->at($reset);
        if ($resetsAt <= $this->clock->now()) {
            return;
        }

        $item = $this->pool->getItem($this->key('reading', $installationId))->set(['remaining' => $remaining, 'reset' => $reset])->expiresAt($resetsAt);
        $this->pool->save($item);
    }

    private function pause(string $installationId, DateTimeImmutable $until): DateTimeImmutable
    {
        // Under the lock every process takes, because two answers in flight could otherwise replace a longer pause with a shorter one.
        $lock = $this->locks->createLock($this->key('pause', $installationId));
        $lock->acquire(true);

        try {
            $item = $this->pool->getItem($this->key('limited', $installationId));
            $longest = max($until, $this->pausedUntil($item) ?? $until);
            $this->pool->save($item->set($longest->getTimestamp())->expiresAt($longest));

            return $longest;
        } finally {
            $lock->release();
        }
    }

    private function pausedUntil(CacheItemInterface $item): ?DateTimeImmutable
    {
        $value = $item->get();

        return $item->isHit() && is_int($value) ? $this->at($value) : null;
    }

    private function at(int $epoch): DateTimeImmutable
    {
        return $this->clock->now()->setTimestamp($epoch);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function number(array $headers, string $name): ?int
    {
        $value = $headers[$name][0] ?? null;

        return is_string($value) && ctype_digit($value) && strlen($value) < 12 ? (int) $value : null;
    }

    // Hashed, because the id comes from the owner's settings and a cache key allows only a narrow set of characters.
    private function key(string $kind, string $installationId): string
    {
        return 'github-rate-limit-'.$kind.'-'.hash('sha256', $installationId);
    }
}
