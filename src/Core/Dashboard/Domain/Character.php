<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The value is the stem of the role's image files, so the files and the role share one name.
enum Character: string
{
    case CodeReviewer = 'code_reviewer';
    case Judge = 'judge';
    case Maintainer = 'maintainer';

    // The files hold 1x and 2x of the sizes the pages show (24 and 48 px), so any other size would name a file that does not exist.
    public function imageWidth(int $size, int $density): int
    {
        if (!in_array($size, [24, 48], true)) {
            throw CharacterSizeHasNoImage::for($this, $size);
        }

        return $size * $density;
    }

    public function label(): string
    {
        return match ($this) {
            self::CodeReviewer => 'Code reviewer',
            self::Judge => 'Judge',
            self::Maintainer => 'Maintainer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CodeReviewer => 'Meerkat with a magnifying glass',
            self::Judge => 'Owl with a gavel',
            self::Maintainer => 'Tortoise with a toolbox',
        };
    }
}
