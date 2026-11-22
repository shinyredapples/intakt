<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class FileContent
{
    public function __construct(
        public string $path,
        public CommitId $commit,
        public string $content,
        public ?int $binaryBytes = null,
    ) {
    }

    // Named by its size instead of carrying its bytes, because what is read about a change reaches prompts and payloads that must be UTF-8.
    public static function binary(string $path, CommitId $commit, int $bytes): self
    {
        return new self($path, $commit, self::binaryText($bytes), $bytes);
    }

    public static function binaryText(int $bytes): string
    {
        return sprintf('Binary file, not shown: %d bytes', $bytes);
    }
}
