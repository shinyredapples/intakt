<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The role character that stands on the current step of a review's path; the value is the stem of its image files.
enum StepRole: string
{
    case CodeReviewer = 'code_reviewer';
    case Developer = 'developer';
    case Maintainer = 'maintainer';

    public function label(): string
    {
        return match ($this) {
            self::CodeReviewer => 'Code reviewer (meerkat)',
            self::Developer => 'Developer (beaver)',
            self::Maintainer => 'Maintainer (tortoise)',
        };
    }
}
