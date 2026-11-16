<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The value is the stem of the role's image files, so the files and the role share one name.
enum Character: string
{
    case CodeReviewer = 'code_reviewer';
    case Judge = 'judge';

    public function label(): string
    {
        return match ($this) {
            self::CodeReviewer => 'Code reviewer',
            self::Judge => 'Judge',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CodeReviewer => 'Meerkat with a magnifying glass',
            self::Judge => 'Owl with a gavel',
        };
    }
}
