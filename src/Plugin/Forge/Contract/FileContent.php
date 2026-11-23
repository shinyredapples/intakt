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
        return new self($path, $commit, 'Binary file, not shown: '.(1 === $bytes ? '1 byte' : $bytes.' bytes'), $bytes);
    }
}
