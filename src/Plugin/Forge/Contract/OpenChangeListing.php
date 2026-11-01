<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class OpenChangeListing
{
    /**
     * @param list<OpenChange>|null $changes
     */
    private function __construct(
        private ?array $changes,
        public ?string $validator,
    ) {
    }

    // The forge answered that the list is the one the validator was taken from, so nothing was read.
    public static function unchanged(): self
    {
        return new self(null, null);
    }

    /**
     * @param list<OpenChange> $changes
     */
    public static function listed(array $changes, ?string $validator): self
    {
        return new self($changes, $validator);
    }

    public function isUnchanged(): bool
    {
        return null === $this->changes;
    }

    /**
     * @return list<OpenChange>
     */
    public function changes(): array
    {
        return $this->changes ?? [];
    }
}
