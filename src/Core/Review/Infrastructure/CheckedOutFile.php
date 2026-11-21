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
        $real = self::realPathOf($checkout, $path);
        $content = null !== $real && is_file($real) && is_readable($real) ? file_get_contents($real) : false;

        return false === $content ? null : $content;
    }

    // Nothing for a path that is a symbolic link or lies under one, because the link's target is another file, which the diff may not touch, and its text would be shown under the link's name.
    public static function contentBehindNoLinkOf(Checkout $checkout, string $path): ?string
    {
        $real = self::realPathOf($checkout, $path);

        return null !== $real && $checkout->relative($real) === $path ? self::contentOf($checkout, $path) : null;
    }

    private static function realPathOf(Checkout $checkout, string $path): ?string
    {
        try {
            return $checkout->resolve($path);
        } catch (PathNotInCheckout|PathOutsideCheckout) {
            // Skipped, because a deleted file is only in the diff and a path outside the checkout is never read.
            return null;
        }
    }
}
