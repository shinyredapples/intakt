<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

final readonly class PassedTree
{
    public const string RECORD = FullReport::FOLDER.'/passed.json';

    private function __construct(private TreeFingerprint $fingerprint, private ?string $taken, private int $startedAt)
    {
    }

    // Because the fingerprint is taken before any gate starts, a tree edited while the gates run never records a pass for what they did not see.
    public static function before(TreeFingerprint $fingerprint, ClockInterface $clock = new NativeClock()): self
    {
        $startedAt = $clock->now()->getTimestamp();

        return new self($fingerprint, $fingerprint->taken(), $startedAt);
    }

    // Because the record is a file the checked code can write too, it only spares a run its caller asked to spare, never one that gates a change.
    public function hasPassed(): bool
    {
        if (null === $this->taken || $this->isLinked() || is_link($this->file()) || !is_file($this->file())) {
            return false;
        }

        $expected = $this->contents($this->taken);

        // Because a record of any size would otherwise be read whole into memory, one byte past the expected length is enough to tell a longer one apart.
        if (@file_get_contents($this->file(), length: strlen($expected) + 1) !== $expected) {
            return false;
        }

        // Because the tree may change between taking the fingerprint and finding its record, the pass stands only for a tree that still gives the same fingerprint once the record is found.
        return $this->fingerprint->taken() === $this->taken;
    }

    // Because only the latest pass is kept, one small file replaced whole never grows, and a temporary name renamed over it never leaves half a record.
    public function recordPass(): bool
    {
        // Because a file changed and changed back while the gates ran shows in its times, unless both changes fell in the second the fingerprint was taken, which the settled test refuses.
        if (null === $this->taken || $this->fingerprint->taken($this->startedAt) !== $this->taken || $this->isLinked() || !$this->folderStaysInside()) {
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

        return $this->stillHoldsBesideItsRecord();
    }

    /**
     * @phpstan-impure
     */
    // Because a var/ or var/check that is a link would read or write a record outside the checked tree.
    private function isLinked(): bool
    {
        return is_link($this->fingerprint->root.'/var') || is_link($this->folder());
    }

    private function file(): string
    {
        return $this->fingerprint->root.'/'.self::RECORD;
    }

    private function contents(string $fingerprint): string
    {
        return '{"fingerprint":"'.$fingerprint.'"}'."\n";
    }

    private function folderStaysInside(): bool
    {
        $root = realpath($this->fingerprint->root);
        $real = realpath($this->folder());

        return false !== $root && false !== $real && is_dir($real) && str_starts_with($real, rtrim($root, '/').'/');
    }

    private function folder(): string
    {
        return $this->fingerprint->root.'/'.FullReport::FOLDER;
    }

    /**
     * @phpstan-impure
     */
    // Because a tree that does not ignore var/check hands the record and the report to its gates as files to judge, the record changes the fingerprint it holds there: such a tree keeps no record and reuses nothing.
    private function stillHoldsBesideItsRecord(): bool
    {
        if ($this->fingerprint->taken($this->startedAt) === $this->taken) {
            return true;
        }

        @unlink($this->file());

        return false;
    }
}
