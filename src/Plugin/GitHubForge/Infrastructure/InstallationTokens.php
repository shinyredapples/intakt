<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateInterval;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use NoDiscard;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

// Every call asks here, because a token held by the caller could outlive its expiry.
final class InstallationTokens implements ResetInterface
{
    // A token this close to its expiry is replaced because it could lapse mid-call.
    private const string MARGIN = 'PT60S';

    private ?InstallationToken $cached = null;

    public function __construct(
        private readonly InstallationTokenMinter $minter,
        public readonly string $installationId,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws InstallationTokenNotMinted
     */
    #[NoDiscard('the token is the credential of the call')]
    public function current(): InstallationToken
    {
        if ($this->cached instanceof InstallationToken && $this->isUsable($this->cached)) {
            return $this->cached;
        }

        $this->cached = null;
        $minted = $this->minter->mint($this->installationId);
        if (!$this->isUsable($minted)) {
            throw InstallationTokenNotMinted::becauseTheTokenLapsesTooSoon();
        }

        $this->cached = $minted;

        return $minted;
    }

    private function isUsable(InstallationToken $token): bool
    {
        return $token->expiresAt > $this->clock->now()->add(new DateInterval(self::MARGIN));
    }

    public function reset(): void
    {
        $this->cached = null;
    }
}
