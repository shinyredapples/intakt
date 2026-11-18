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
        $key = new FindingKey();
        $hashes = $this->hashesAt($key->namedPaths($finding), $checkoutRoot);

        return null === $hashes ? null : $key->of($finding, $hashes);
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, string>|null
     */
    private function hashesAt(array $paths, string $checkoutRoot): ?array
    {
        // None when the checkout is gone, so a removed snapshot never fails a judging.
        if (!is_dir($checkoutRoot)) {
            return null;
        }

        $checkout = new Checkout($checkoutRoot);
        $hashes = [];

        foreach ($paths as $path) {
            $hash = $this->hashOf($checkout, $path);

            if (null === $hash) {
                return null;
            }

            $hashes[$path] = $hash;
        }

        return $hashes;
    }

    private function hashOf(Checkout $checkout, string $path): ?string
    {
        try {
            $real = $checkout->resolve($path);
        } catch (PathNotInCheckout|PathOutsideCheckout) {
            // No hash, because a missing path has no content and one outside the checkout is never read.
            return null;
        }

        if (!is_file($real) || !is_readable($real)) {
            return null;
        }

        $hash = hash_file('sha256', $real);

        return false === $hash ? null : $hash;
    }
}
