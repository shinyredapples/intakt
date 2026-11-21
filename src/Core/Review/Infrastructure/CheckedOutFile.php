<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;

// One home for reading a touched file at the head, because the quote check and the nearest lines must skip the same paths.
final readonly class CheckedOutFile
{
    private const int FILE_TYPE = 0o170000;
    private const int REGULAR_FILE = 0o100000;

    public static function contentOf(Checkout $checkout, string $path): ?string
    {
        $real = self::realPathOf($checkout, $path);

        return null === $real ? null : self::sameRegularFileContentOf($real);
    }

    // Nothing for a path that is a symbolic link or lies under one, because the link's target is another file, which the diff may not touch, and its text would be shown under the link's name.
    public static function contentBehindNoLinkOf(Checkout $checkout, string $path): ?string
    {
        $real = self::realPathOf($checkout, $path);

        return null !== $real && $checkout->relative($real) === $path ? self::sameRegularFileContentOf($real) : null;
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

    // Warnings silenced, because a file that went or turned unreadable after it was resolved gives nothing rather than an error.
    private static function sameRegularFileContentOf(string $real): ?string
    {
        set_error_handler(static fn (): bool => true);

        try {
            return self::contentOfOpenedRegularFile($real);
        } finally {
            restore_error_handler();
        }
    }

    // Opened once and read only when the open file is the regular file just checked, because a file swapped for a link after the check would show the link's target.
    private static function contentOfOpenedRegularFile(string $real): ?string
    {
        clearstatcache(true, $real);
        $checked = lstat($real);

        if (false === $checked || self::REGULAR_FILE !== ($checked['mode'] & self::FILE_TYPE)) {
            return null;
        }

        $handle = fopen($real, 'rb');

        if (false === $handle) {
            return null;
        }

        try {
            $opened = fstat($handle);

            if (false === $opened || $opened['dev'] !== $checked['dev'] || $opened['ino'] !== $checked['ino']) {
                return null;
            }

            $content = stream_get_contents($handle);

            return false === $content ? null : $content;
        } finally {
            fclose($handle);
        }
    }
}
