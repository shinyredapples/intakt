<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;
use Intakt\Core\Review\Domain\FindingKey;

// Null for a finding naming any path that is no readable regular file of the checkout at the head, so such a finding is judged again instead of matching a verdict on other code.
final readonly class FindingKeys
{
    public function keyOf(Finding $finding, string $checkoutRoot): ?string
    {
        // None when the checkout is gone, so a removed snapshot never fails a judging.
        if (!is_dir($checkoutRoot)) {
            return null;
        }

        $key = new FindingKey();
        $checkout = new Checkout($checkoutRoot);
        $named = $this->hashesAt($checkout, $key->namedPaths($finding));
        $possible = $this->hashesOfFilesAt($checkout, $key->possiblePaths($finding));

        return null === $named || null === $possible ? null : $key->of($finding, [...$named, ...$possible]);
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, string>|null
     */
    private function hashesAt(Checkout $checkout, array $paths): ?array
    {
        $hashes = [];

        foreach ($paths as $path) {
            $real = $this->regularFileAt($checkout, $path);
            $hash = null === $real ? null : $this->hashOf($real);

            if (null === $hash) {
                return null;
            }

            $hashes[$path] = $hash;
        }

        return $hashes;
    }

    // A word that is no regular file of the checkout is prose and skipped; one that is a file but cannot be read refuses the key, because its content is part of what the finding names.
    /**
     * @param list<string> $paths
     *
     * @return array<string, string>|null
     */
    private function hashesOfFilesAt(Checkout $checkout, array $paths): ?array
    {
        $hashes = [];

        foreach ($paths as $path) {
            $real = $this->regularFileAt($checkout, $path);

            if (null === $real) {
                continue;
            }

            $hash = $this->hashOf($real);

            if (null === $hash) {
                return null;
            }

            $hashes[$path] = $hash;
        }

        return $hashes;
    }

    private function regularFileAt(Checkout $checkout, string $path): ?string
    {
        try {
            $real = $checkout->resolve($path);
        } catch (PathNotInCheckout|PathOutsideCheckout) {
            // No file, because a missing path has no content and one outside the checkout is never read.
            return null;
        }

        return is_file($real) ? $real : null;
    }

    private function hashOf(string $real): ?string
    {
        if (!is_readable($real)) {
            return null;
        }

        $hash = hash_file('sha256', $real);

        return false === $hash ? null : $hash;
    }
}
