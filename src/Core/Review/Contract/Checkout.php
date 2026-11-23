<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The one place that decides what is inside the checkout, so the tools and the listener that denies their calls cannot disagree.
final readonly class Checkout
{
    private const string GIT_DIRECTORY = '.git';

    private string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);

        if (false === $real || !is_dir($real)) {
            throw CheckoutNotADirectory::create();
        }

        $this->root = $real;
    }

    /**
     * @throws PathOutsideCheckout
     * @throws PathNotInCheckout
     */
    public function resolve(string $path): string
    {
        $this->refuseUnlessRelativeAndPlain($path);

        $real = realpath($this->root.'/'.$path);

        if (false === $real) {
            throw PathNotInCheckout::for($path);
        }

        // A symlink in the code under review can point anywhere, including the git directory, so the real location decides.
        if (!$this->isInside($real) || $this->isGitDirectory($real)) {
            throw PathOutsideCheckout::for($path);
        }

        return $real;
    }

    public function relative(string $realPath): string
    {
        return ltrim(substr($realPath, strlen($this->root)), '/');
    }

    private function refuseUnlessRelativeAndPlain(string $path): void
    {
        if (str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\') || 1 === preg_match('/^[A-Za-z]:/', $path)) {
            throw PathOutsideCheckout::for($path);
        }

        // Because the git directory is not part of the head and holds the repository's own config.
        $segments = explode('/', str_replace('\\', '/', $path));

        if (in_array('..', $segments, true) || in_array(self::GIT_DIRECTORY, $segments, true)) {
            throw PathOutsideCheckout::for($path);
        }
    }

    private function isInside(string $real): bool
    {
        return $real === $this->root || str_starts_with($real, $this->root.'/');
    }

    private function isGitDirectory(string $real): bool
    {
        return in_array(self::GIT_DIRECTORY, explode('/', $this->relative($real)), true);
    }
}
