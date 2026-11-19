<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

use InvalidArgumentException;

final readonly class WatchedMain
{
    public ?string $lastSeenHead;

    /**
     * @param list<RecordedMerge> $recordedMerges
     * @param list<string>        $ownerKeys
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public string $repository,
        ?string $lastSeenHead,
        public array $recordedMerges,
        public array $ownerKeys,
    ) {
        $this->lastSeenHead = null === $lastSeenHead ? null : CommitName::must($lastSeenHead);

        foreach ($ownerKeys as $key) {
            if (1 !== preg_match('/\A[!-~]+ [!-~]+\z/', $key)) {
                throw new InvalidArgumentException('An owner key is a public key line: its type, then its base64 body.');
            }
        }
    }
}
