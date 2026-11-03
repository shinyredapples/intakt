<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// In a cache every worker and the scheduler share, so all processes of one installation see its latest budget and its pause.
final readonly class RateLimitReadings
{
    public const string POOL = 'intakt.github.rate_limits';
    private const array LIMITED_STATUSES = [403, 429];

    // One minute because GitHub named no reset, which is also the shortest wait its secondary limits ask for.
    private const int SECONDS_WITHOUT_RESET = 60;

    // At most an hour, because the primary limit resets hourly and a header must not park the installation for longer.
    private const int LONGEST_SECONDS = 3600;

    public function __construct(
        #[Autowire(service: self::POOL)]
        private CacheItemPoolInterface $pool,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ForgeRateLimited
     */
    public function refuseWhileLimited(string $installationId): void
    {
        $until = $this->pool->getItem($this->key('limited', $installationId));

        // Compared with the clock because the cache expiry runs on system time.
        if ($until->isHit() && is_int($until->get()) && $this->at($until->get()) > $this->clock->now()) {
            throw new ForgeRateLimited($this->at($until->get()));
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
            $this->pause($installationId, $until);

            throw new ForgeRateLimited($until);
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

        $retryAfter = $this->number($headers, 'retry-after');
        if (null !== $retryAfter) {
            return $this->after($retryAfter);
        }

        if (0 !== $this->number($headers, 'x-ratelimit-remaining')) {
            return null;
        }

        $reset = $this->number($headers, 'x-ratelimit-reset');

        return null === $reset ? $this->after(self::SECONDS_WITHOUT_RESET) : $this->capped($this->at($reset));
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

    private function pause(string $installationId, DateTimeImmutable $until): void
    {
        $this->pool->save($this->pool->getItem($this->key('limited', $installationId))->set($until->getTimestamp())->expiresAt($until));
    }

    private function after(int $seconds): DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('+%d seconds', min(max($seconds, 1), self::LONGEST_SECONDS)));
    }

    private function capped(DateTimeImmutable $moment): DateTimeImmutable
    {
        $latest = $this->clock->now()->modify(sprintf('+%d seconds', self::LONGEST_SECONDS));

        return min($moment, $latest);
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
