<?php

declare(strict_types=1);

namespace Intakt\Gates;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// Because zizmor honors `# zizmor: ignore[<audit>]` with or without words after it, and the project accepts an ignore only with its reason on the same line.
final readonly class BareZizmorIgnores
{
    private const string SCANNED = '.github';
    // Looser than zizmor's own syntax, so a near miss that zizmor might still honor needs a reason too.
    private const string DIRECTIVE = '/#\s*zizmor:\s*ignore\[[^\]]*\]/i';

    /**
     * @return list<string>
     */
    public function in(string $root): array
    {
        $findings = [];

        foreach ($this->yamlFiles($root) as $path) {
            $findings = [...$findings, ...$this->inFile($root, $path)];
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function inFile(string $root, string $path): array
    {
        $contents = is_readable($root.'/'.$path) ? file_get_contents($root.'/'.$path) : false;

        if (false === $contents) {
            return [$path.' cannot be read for zizmor ignores'];
        }

        $findings = [];

        // YAML ends a line at CR LF, CR or LF, so splitting at LF alone would make the next lines of a CR file the ignore's reason.
        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $index => $line) {
            if (1 === preg_match(self::DIRECTIVE, $line, $directive, PREG_OFFSET_CAPTURE) && !$this->hasReason(substr($line, $directive[0][1]))) {
                $findings[] = $path.':'.($index + 1).' zizmor ignore without a reason: '.trim($directive[0][0]);
            }
        }

        return $findings;
    }

    // A reason is words, so blanks, punctuation or a second directive alone do not count as one.
    private function hasReason(string $fromDirective): bool
    {
        return 1 === preg_match('/\p{L}/u', preg_replace(self::DIRECTIVE, '', $fromDirective) ?? '');
    }

    /**
     * @return list<string>
     */
    private function yamlFiles(string $root): array
    {
        if (!is_dir($root.'/'.self::SCANNED)) {
            return [];
        }

        $paths = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.self::SCANNED, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && in_array(strtolower($file->getExtension()), ['yml', 'yaml'], true)) {
                $paths[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($paths);

        return $paths;
    }
}
