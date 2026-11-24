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

    // One rule for every forge, because a file one forge names binary must not reach a prompt as bytes from another.
    public static function ofBytes(string $path, CommitId $commit, string $bytes): self
    {
        return new BinaryFiles()->isBinary($bytes) ? self::binary($path, $commit, strlen($bytes)) : new self($path, $commit, $bytes);
    }

    // Named by its size instead of carrying its bytes, because what is read about a change reaches prompts and payloads that must be UTF-8.
    public static function binary(string $path, CommitId $commit, int $bytes): self
    {
        return new self($path, $commit, self::binaryText($bytes), $bytes);
    }

    public static function binaryText(int $bytes): string
    {
        return 'Binary file, not shown: '.(1 === $bytes ? '1 byte' : $bytes.' bytes');
    }
}
