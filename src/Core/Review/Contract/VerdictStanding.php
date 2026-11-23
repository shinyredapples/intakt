<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Git\Contract\Paths;

final readonly class VerdictStanding
{
    /**
     * @param list<IdentityField> $changed
     */
    private function __construct(
        public Standing $standing,
        public array $changed,
        public Paths $reviewAgain,
    ) {
    }

    public static function current(): self
    {
        return new self(Standing::Current, [], new Paths());
    }

    /**
     * @param list<IdentityField> $changed
     */
    public static function keptExceptFor(array $changed, Paths $touched): self
    {
        return new self(Standing::KeptForUntouchedParts, $changed, $touched);
    }

    /**
     * @param list<IdentityField> $changed
     */
    public static function stale(array $changed): self
    {
        return new self(Standing::Stale, $changed, new Paths());
    }
}
