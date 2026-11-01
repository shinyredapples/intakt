<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateInterval;
use Intakt\Plugin\GitHubForge\Domain\InstallationToken;
use Intakt\Plugin\GitHubForge\Domain\InstallationTokenNotMinted;
use NoDiscard;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

// Every forge call and every git fetch or push asks here, so none of them holds a token past its expiry.
final class InstallationTokens implements ResetInterface
{
    // A token this close to its expiry could lapse mid-call.
    private const string MARGIN = 'PT60S';

    private ?InstallationToken $cached = null;

    public function __construct(
        private readonly InstallationTokenMinter $minter,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws InstallationTokenNotMinted
     */
    #[NoDiscard('the token is the credential of the call')]
    public function current(): InstallationToken
    {
        if ($this->cached !== null && $this->cached->expiresAt > $this->clock->now()->add(new DateInterval(self::MARGIN))) {
            return $this->cached;
        }

        $this->cached = $this->minter->mint();

        return $this->cached;
    }

    public function reset(): void
    {
        $this->cached = null;
    }
}
