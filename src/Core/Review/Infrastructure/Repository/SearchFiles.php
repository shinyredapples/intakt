<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository;

use FilesystemIterator;
use Generator;
use Intakt\Core\Review\Contract\Checkout;
use InvalidArgumentException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool(name: 'search_files', description: 'Finds the lines of the repository at the head under review that contain a text; answers path:line:text.', effectHints: new ToolEffectHints(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
final readonly class SearchFiles
{
    // Because the answer is part of the prompt, and a file larger than this is generated or binary.
    private const int MAX_MATCHES = 100;
    private const int MAX_FILE_BYTES = 1_000_000;
    // Because one long line, as in a minified file, would otherwise make a match as large as its file.
    private const int MAX_LINE_BYTES = 500;
    private const int MAX_ANSWER_BYTES = 100_000;

    public function __construct(private Checkout $checkout)
    {
    }

    public function __invoke(
        #[Schema(description: 'Text to find, matched literally and case-sensitively.')] string $text,
        #[Schema(description: 'File or directory to search, relative to the repository root; "." is the root.')] string $path = '.',
    ): string {
        if ('' === $text) {
            throw new InvalidArgumentException('The text to find is empty.');
        }

        $matches = [];
        $bytes = 0;

        foreach ($this->matches($this->checkout->resolve($path), $text) as $match) {
            $bytes += strlen($match) + 1;

            if (count($matches) >= self::MAX_MATCHES || $bytes > self::MAX_ANSWER_BYTES) {
                return implode("\n", $matches)."\n[more matches not shown]";
            }

            $matches[] = $match;
        }

        return [] === $matches ? 'No match.' : implode("\n", $matches);
    }

    /**
     * @return Generator<string>
     */
    private function matches(string $real, string $text): Generator
    {
        foreach ($this->files($real) as $file) {
            yield from $this->matchesIn($file, $text);
        }
    }

    /**
     * @return Generator<SplFileInfo>
     */
    private function files(string $real): Generator
    {
        if (is_file($real)) {
            yield new SplFileInfo($real);

            return;
        }

        // Because a link in the code under review can point out of the checkout, symlinks are not followed.
        $entries = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $entry): bool => '.git' !== $entry->getFilename() && !$entry->isLink(),
        ));

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                yield $entry;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function matchesIn(SplFileInfo $file, string $text): array
    {
        $content = $file->getSize() <= self::MAX_FILE_BYTES ? file_get_contents($file->getPathname()) : false;

        // Because the answer reaches the provider as JSON, which carries only valid UTF-8, a binary file is not searched.
        if (false === $content || !mb_check_encoding($content, 'UTF-8')) {
            return [];
        }

        $relative = mb_scrub($this->checkout->relative($file->getPathname()), 'UTF-8');
        $matches = [];

        foreach (explode("\n", $content) as $number => $line) {
            if (str_contains($line, $text)) {
                $matches[] = sprintf('%s:%d:%s', $relative, $number + 1, $this->cut(rtrim($line, "\r")));
            }
        }

        return $matches;
    }

    private function cut(string $line): string
    {
        return strlen($line) > self::MAX_LINE_BYTES ? mb_strcut($line, 0, self::MAX_LINE_BYTES, 'UTF-8').' [line cut]' : $line;
    }
}
