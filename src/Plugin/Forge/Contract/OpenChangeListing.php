<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class OpenChangeListing
{
    /**
     * @param list<OpenChange> $changes
     */
    private function __construct(
        public bool $unchanged,
        public array $changes,
        public ?string $validator,
    ) {
    }

    // The forge answered that the list is the one the validator was taken from, so nothing was read.
    public static function unchanged(): self
    {
        return new self(true, [], null);
    }

    /**
     * @param list<OpenChange> $changes
     */
    public static function listed(array $changes, ?string $validator): self
    {
        return new self(false, $changes, $validator);
    }
}
