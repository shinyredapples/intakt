<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class VerdictStanding
{
    /**
     * @param list<IdentityField> $changed
     * @param list<string>        $reviewAgain
     */
    private function __construct(
        public Standing $standing,
        public array $changed,
        public array $reviewAgain,
    ) {
    }

    public static function current(): self
    {
        return new self(Standing::Current, [], []);
    }

    /**
     * @param list<IdentityField> $changed
     * @param list<string>        $touched
     */
    public static function keptExceptFor(array $changed, array $touched): self
    {
        return new self(Standing::KeptForUntouchedParts, $changed, $touched);
    }

    /**
     * @param list<IdentityField> $changed
     */
    public static function stale(array $changed): self
    {
        return new self(Standing::Stale, $changed, []);
    }
}
