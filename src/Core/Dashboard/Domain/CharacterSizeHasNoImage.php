<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use InvalidArgumentException;

final class CharacterSizeHasNoImage extends InvalidArgumentException
{
    public static function for(Character $character, int $size): self
    {
        return new self(sprintf('No image files exist for the %s at %d px.', $character->value, $size));
    }
}
