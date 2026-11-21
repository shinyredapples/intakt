<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;

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
        $contents = array_map(static fn (string $path): ?string => CheckedOutFile::contentOf($checkout, $path), $this->paths->in($diff));

        return array_values(array_filter($contents, static fn (?string $content): bool => null !== $content));
    }
}
