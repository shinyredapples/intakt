<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use InvalidArgumentException;

// The diff and each file it touches as the checkout holds it, because a finding may rightly quote a line of a touched file the diff does not show.
final readonly class ChangeText
{
    public function __construct(
        private DiffPaths $paths,
    ) {
    }

    public function of(string $checkoutRoot, string $diff): string
    {
        $checkout = $this->checkoutAt($checkoutRoot);

        if (!$checkout instanceof Checkout) {
            return $diff;
        }

        $files = array_map(fn (string $path): string => $this->fileText($checkout, $path), $this->paths->in($diff));

        return implode("\n", [$diff, ...array_filter($files, static fn (string $text): bool => '' !== $text)]);
    }

    // Null for a checkout that is gone, so the diff alone is what the change shows.
    private function checkoutAt(string $root): ?Checkout
    {
        try {
            return new Checkout($root);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    // Empty for a path the checkout refuses or no longer holds, because a deleted file is in the diff already and a link out of the checkout must never be read.
    private function fileText(Checkout $checkout, string $path): string
    {
        try {
            $real = $checkout->resolve($path);
        } catch (PathOutsideCheckout|PathNotInCheckout) {
            return '';
        }

        if (!is_file($real) || !is_readable($real)) {
            return '';
        }

        return (string) file_get_contents($real);
    }
}
