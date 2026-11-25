<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownReader
{
    private const string MEASURED_PAGE = '#^(?:README\.md|docs/.+\.md)$#';

    public function __construct(private MarkdownCodeBlocks $codeBlocks)
    {
    }

    /**
     * @param list<string> $paths
     *
     * @return list<MarkdownPage>
     */
    public function pages(string $root, array $paths): array
    {
        $measured = array_values(array_filter($paths, static fn (string $path): bool => 1 === preg_match(self::MEASURED_PAGE, $path)));
        sort($measured);

        return array_map(fn (string $path): MarkdownPage => $this->page($root, $path), $measured);
    }

    private function page(string $root, string $path): MarkdownPage
    {
        $file = $root.'/'.$path;
        $contents = is_file($file) && is_readable($file) ? file_get_contents($file) : false;

        // A page that cannot be read must fail the reader, otherwise its paragraphs would silently go unmeasured.
        if (false === $contents) {
            throw new MarkdownReadFailure('cannot read '.$path);
        }

        // The word patterns match nothing in invalid UTF-8, so its paragraphs would count no words.
        if (!mb_check_encoding($contents, 'UTF-8')) {
            throw new MarkdownReadFailure($path.' is not valid UTF-8');
        }

        $lines = explode("\n", $contents);
        $blanked = $this->codeBlocks->blanked($lines);

        // The lines the blanking changed are the fenced code lines, so fences are found only once.
        return new MarkdownPage($path, $blanked, array_keys(array_diff_assoc($lines, $blanked)));
    }
}
