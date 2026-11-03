<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class PathsOnBranch
{
    private const string LEADING_DOT_SLASH = '#^(?:\./)+#';

    /**
     * @param array<string, true>|null $known
     */
    private function __construct(
        private string $root,
        private ?array $known,
    ) {
    }

    // Without git there is no branch, so the working tree stands in for it.
    public static function of(string $root, ?ProcessResult $tracked): self
    {
        if (!$tracked instanceof ProcessResult) {
            return new self($root, null);
        }

        $known = [];

        foreach (explode("\0", $tracked->output) as $file) {
            for ($path = $file; '' !== $path && '.' !== $path; $path = dirname($path)) {
                $known[$path] = true;
            }
        }

        return new self($root, $known);
    }

    public function exists(string $path): bool
    {
        $path = $this->normalized($path);

        return null === $this->known ? file_exists($this->root.'/'.$path) : isset($this->known[$path]);
    }

    public function normalized(string $path): string
    {
        return rtrim(preg_replace(self::LEADING_DOT_SLASH, '', $path) ?? $path, '/');
    }
}
