<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Which part of a change a reviewer judges and how its vote counts: the Technical writer reads only the docs and blocks apart from the quorum, so its approval never stands in for a Code reviewer's (docs/design/05-architecture/07-reviews.md#the-technical-writer).
enum ReviewLane: string
{
    case Code = 'code';
    case Docs = 'docs';

    // From the stored role name, because a round keeps the role of each reviewer and nothing else tells the lanes apart.
    public static function ofRole(string $role): self
    {
        return ReviewerRole::TECHNICAL_WRITER === $role ? self::Docs : self::Code;
    }
}
