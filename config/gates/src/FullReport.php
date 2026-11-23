<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class FullReport
{
    // Because Intakt's own .gitignore leaves out var/, and the ASCII-only report keeps a tree whose gates do scan var/ passing.
    public const string FOLDER = 'var/check';

    // Because $ also matches before a final newline, \z ends the name, so a hand-made name ending in a newline never counts as generated.
    public const string GENERATED = '~\Areport-[0-9a-f]{16}\.json\z~';

    private const int KEPT = 20;

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

        $name = 'report-'.bin2hex(random_bytes(8)).'.json';
        $handle = @fopen($folder.'/'.$name, 'x');

        if (false === $handle) {
            return null;
        }

        $file = $this->writtenAs(self::FOLDER.'/'.$name, $handle, $contents."\n");

        if (null !== $file) {
            $this->pruneOlderReportsIn($folder, $name);
        }

        return $file;
    }

    private function contents(): ?string
    {
        $gates = [];
        $whole = true;

        foreach ($this->reports as $report) {
            $gate = $this->ascii($report->kept ?? $report->line);

            if (null === $gate) {
                return null;
            }

            $gates[] = $gate;
            $whole = $whole && (null !== $report->kept || !$report->lineIsCut());
        }

        return '{"whole":'.($whole ? 'true' : 'false').',"gates":['.implode(',', $gates).']}';
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

    // Because a report the summary names must hold everything, a failed or short write leaves no file, and its warning never reaches stdout between the JSON lines.
    /**
     * @param resource $handle
     */
    private function writtenAs(string $file, $handle, string $contents): ?string
    {
        $written = @fwrite($handle, $contents);
        $closed = @fclose($handle);

        if (strlen($contents) === $written && $closed) {
            return $file;
        }

        @unlink($this->root.'/'.$file);

        return null;
    }

    // Because a glob() pattern built from the checked path would read a tree named like tree[a] as a pattern, the literal folder is listed, and only generated names count, so a hand-made report and this run's own report always stay.
    private function pruneOlderReportsIn(string $folder, string $written): void
    {
        $older = $this->otherGeneratedReportsIn($folder, $written);
        arsort($older);

        foreach (array_slice(array_keys($older), self::KEPT - 1) as $path) {
            @unlink($path);
        }
    }

    /**
     * @return array<string, int>
     */
    private function otherGeneratedReportsIn(string $folder, string $written): array
    {
        $times = [];

        foreach ($this->generatedNamesIn($folder) as $name) {
            $path = $folder.'/'.$name;

            if ($name !== $written && !is_link($path) && is_file($path)) {
                $times[$path] = (int) @filemtime($path);
            }
        }

        return $times;
    }

    /**
     * @return list<string>
     */
    private function generatedNamesIn(string $folder): array
    {
        $names = @scandir($folder);

        if (false === $names) {
            return [];
        }

        $generated = array_filter($names, static fn (string $name): bool => 1 === preg_match(self::GENERATED, $name));

        return array_values($generated);
    }

    // Because decoding and encoding again changes a result's shape (an empty object, numbered keys, a number too large for a float), the bytes are copied and only each character past ASCII becomes its \u escape, so no gate that reads the tree finds a long dash or broken byte in it.
    private function ascii(string $json): ?string
    {
        if (!json_validate($json)) {
            return null;
        }

        return preg_replace_callback('/[^\x00-\x7F]/u', static fn (array $character): string => substr(json_encode($character[0], JSON_THROW_ON_ERROR), 1, -1), $json);
    }
}
