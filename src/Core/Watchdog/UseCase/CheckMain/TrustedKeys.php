<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

// The keys a check trusts travel together, because every read of main judges a signature against both signer classes.
final readonly class TrustedKeys
{
    /**
     * @param list<string> $owner
     * @param list<string> $peer
     */
    public function __construct(
        public array $owner,
        public array $peer,
    ) {
    }
}
