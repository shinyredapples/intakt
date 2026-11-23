<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownReader
{
    private const string MEASURED_PAGE = '#^(?:README\.md|docs/.+\.md)$#';

    public function __construct(private FencedCodeBlocks $codeBlocks)
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

        return new MarkdownPage($path, $this->codeBlocks->blanked(explode("\n", $contents)));
    }
}
