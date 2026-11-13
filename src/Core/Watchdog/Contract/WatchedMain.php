<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

use InvalidArgumentException;

final readonly class WatchedMain
{
    // A full object name of either format, because a name that starts with a dash would be an option and a ref name could move while the check runs.
    private const string COMMIT_NAME = '/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/';

    /**
     * @param ?string      $checkedUpTo    the head of main the watchdog last passed; null for a main never checked
     * @param list<string> $recordedMerges merge commits Intakt recorded in its ledger
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public ?string $checkedUpTo,
        public array $recordedMerges,
        public OwnerKeys $ownerKeys,
    ) {
        foreach ([...null === $checkedUpTo ? [] : [$checkedUpTo], ...$recordedMerges] as $commit) {
            if (1 !== preg_match(self::COMMIT_NAME, $commit)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a full commit name', $commit));
            }
        }
    }
}
