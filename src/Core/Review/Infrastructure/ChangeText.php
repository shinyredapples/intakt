<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;

// The diff and the files it touches at the head, because a finding may rightly quote code around the changed lines.
final readonly class ChangeText
{
    public function __construct(private DiffPaths $paths)
    {
    }

    public function of(string $diff, string $checkoutRoot): string
    {
        return implode("\n", [$diff, ...$this->touchedFilesIn($diff, $checkoutRoot)]);
    }

    /**
     * @return list<string>
     */
    private function touchedFilesIn(string $diff, string $checkoutRoot): array
    {
        // The diff alone when the checkout is gone, so a judging never fails on a removed snapshot.
        if (!is_dir($checkoutRoot)) {
            return [];
        }

        $checkout = new Checkout($checkoutRoot);
        $contents = array_map(static fn (string $path): ?string => self::contentOf($checkout, $path), $this->paths->in($diff));

        return array_values(array_filter($contents, static fn (?string $content): bool => null !== $content));
    }

    private static function contentOf(Checkout $checkout, string $path): ?string
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
