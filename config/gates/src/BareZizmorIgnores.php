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
        $lines = preg_split('/\r\n|\r|\n/', $contents);

        foreach (false === $lines ? [] : $lines as $index => $line) {
            $bare = $this->bareDirectiveOf($line);

            if (null !== $bare) {
                $findings[] = $path.':'.($index + 1).' zizmor ignore without a reason: '.$bare;
            }
        }

        return $findings;
    }

    // A YAML comment runs to the end of the line, so the directive zizmor honors is the last match; an earlier one may sit in a quoted value, and the words after it must not pass as the comment's reason.
    private function bareDirectiveOf(string $line): ?string
    {
        preg_match_all(self::DIRECTIVE, $line, $directives, PREG_OFFSET_CAPTURE);
        $last = array_last($directives[0]);

        if (null === $last) {
            return null;
        }

        [$directive, $offset] = $last;

        return $this->hasReason(substr($line, $offset + strlen($directive))) ? null : trim($directive);
    }

    // A reason is words, so blanks or punctuation alone do not count as one.
    private function hasReason(string $afterDirective): bool
    {
        return 1 === preg_match('/\p{L}/u', $afterDirective);
    }
}
