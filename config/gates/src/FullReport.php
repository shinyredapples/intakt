<?php

declare(strict_types=1);

namespace Intakt\Gates;

use JsonException;

final readonly class FullReport
{
    // Because Intakt's own .gitignore leaves out var/, and the ASCII-only report keeps a tree whose gates do scan var/ passing.
    public const string FOLDER = 'var/check';

    /**
     * @param list<GateReport> $reports
     */
    public function __construct(private string $root, private array $reports)
    {
    }

    // Because a fresh random name, created exclusively, never writes over an earlier report, and a check that loses the mkdir() race to another check still writes.
    public function write(): ?string
    {
        $contents = $this->contents();
        $folder = $this->root.'/'.self::FOLDER;

        if (null === $contents || $this->isLinked() || (!@mkdir($folder, 0777, true) && !is_dir($folder))) {
            return null;
        }

        if ($this->isLinked() || !$this->staysInside($folder)) {
            return null;
        }

        $file = self::FOLDER.'/report-'.bin2hex(random_bytes(8)).'.json';
        $handle = @fopen($this->root.'/'.$file, 'x');

        if (false === $handle) {
            return null;
        }

        // TODO(#2652): a short write still gets a report key, because it is not detected yet.
        fwrite($handle, $contents."\n");
        fclose($handle);

        return $file;
    }

    /**
     * @phpstan-impure
     */
    // Because a var/ or var/check that is a link would put the report outside the checked tree, it is refused before and after mkdir().
    private function isLinked(): bool
    {
        return is_link($this->root.'/var') || is_link($this->root.'/'.self::FOLDER);
    }

    private function staysInside(string $folder): bool
    {
        $root = realpath($this->root);
        $real = realpath($folder);

        return false !== $root && false !== $real && str_starts_with($real, rtrim($root, '/').'/');
    }

    // Because each gate is encoded on its own, a kept result PHP cannot encode again falls back to that gate's line and costs no other gate its findings.
    private function contents(): ?string
    {
        $gates = [];
        $whole = true;

        foreach ($this->reports as $report) {
            $kept = null === $report->kept ? null : self::encoded($report->kept);
            $gate = $kept ?? self::encoded($report->line);

            if (null === $gate) {
                return null;
            }

            $gates[] = $gate;
            $whole = $whole && (null !== $kept || !$report->lineIsCut());
        }

        return '{"whole":'.($whole ? 'true' : 'false').',"gates":['.implode(',', $gates).']}';
    }

    // Because the default escaping writes every character past ASCII as \u escapes, so no gate that reads the tree finds a long dash or broken byte in it.
    private static function encoded(string $json): ?string
    {
        try {
            return json_encode(json_decode($json, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return null;
        }
    }
}
