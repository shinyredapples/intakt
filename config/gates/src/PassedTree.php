<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class PassedTree
{
    public const string RECORD = FullReport::FOLDER.'/passed.json';

    private function __construct(private TreeFingerprint $fingerprint, private ?string $taken)
    {
    }

    // Because the fingerprint is taken before any gate starts, a tree edited while the gates run never records a pass for what they did not see.
    public static function before(TreeFingerprint $fingerprint): self
    {
        return new self($fingerprint, $fingerprint->taken());
    }

    public function hasPassed(): bool
    {
        if (null === $this->taken || $this->isLinked() || is_link($this->file()) || !is_file($this->file())) {
            return false;
        }

        return @file_get_contents($this->file()) === $this->contents($this->taken);
    }

    // Because only the latest pass is kept, one small file replaced whole never grows, and a temporary name renamed over it never leaves half a record.
    public function recordPass(): bool
    {
        if (null === $this->taken || $this->fingerprint->taken() !== $this->taken || $this->isLinked() || !$this->folderStaysInside()) {
            return false;
        }

        $temporary = $this->folder().'/passed-'.bin2hex(random_bytes(8)).'.tmp';
        $handle = @fopen($temporary, 'x');

        if (false === $handle) {
            return false;
        }

        $contents = $this->contents($this->taken);
        $written = @fwrite($handle, $contents);

        if (!@fclose($handle) || strlen($contents) !== $written || !@rename($temporary, $this->file())) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    private function contents(string $fingerprint): string
    {
        return '{"fingerprint":"'.$fingerprint.'"}'."\n";
    }

    private function folder(): string
    {
        return $this->fingerprint->root.'/'.FullReport::FOLDER;
    }

    private function file(): string
    {
        return $this->fingerprint->root.'/'.self::RECORD;
    }

    /**
     * @phpstan-impure
     */
    // Because a var/ or var/check that is a link would read or write a record outside the checked tree.
    private function isLinked(): bool
    {
        return is_link($this->fingerprint->root.'/var') || is_link($this->folder());
    }

    private function folderStaysInside(): bool
    {
        $root = realpath($this->fingerprint->root);
        $real = realpath($this->folder());

        return false !== $root && false !== $real && is_dir($real) && str_starts_with($real, rtrim($root, '/').'/');
    }
}
