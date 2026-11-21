<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;

// One home for reading a touched file at the head, because the quote check and the nearest lines must skip the same paths.
final readonly class CheckedOutFile
{
    public static function contentOf(Checkout $checkout, string $path): ?string
    {
        try {
            $real = $checkout->resolve($path);
        } catch (PathNotInCheckout|PathOutsideCheckout) {
            // Skipped, because a deleted file is only in the diff and a path outside the checkout is never read.
            return null;
        }

        $content = is_file($real) && is_readable($real) ? file_get_contents($real) : false;

        return false === $content ? null : $content;
    }
}
