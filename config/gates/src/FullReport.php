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

        if (null === $contents || $this->isLinked() || (!@mkdir($folder, 0777, true) && !is_dir($folder)) || $this->isLinked() || !$this->staysInside($folder)) {
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

    // Because the default escaping writes every character past ASCII as \u escapes, so no gate that reads the tree finds a long dash or broken byte in it.
    private function contents(): ?string
    {
        $gates = array_map(static fn (GateReport $report): mixed => json_decode($report->kept ?? $report->line), $this->reports);

        try {
            return json_encode(['whole' => $this->isWhole(), 'gates' => $gates], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return null;
        }
    }

    // Because a gate whose child kept nothing reports only its line, the report is whole only when no such line lost a finding.
    private function isWhole(): bool
    {
        return array_all($this->reports, static fn (GateReport $report): bool => null !== $report->kept || !$report->lineIsCut());
    }
}
