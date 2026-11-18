<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository;

use Closure;
use FilesystemIterator;
use Generator;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\ReviewTool;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\AI\Platform\Tool\ToolEffectHints;
use UnexpectedValueException;

#[AsTool(name: ReviewTool::SearchFiles->value, description: 'Finds the lines of the repository at the head under review that contain a text; answers path:line:text.', effectHints: new ToolEffectHints(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
final readonly class SearchFiles
{
    // Because the answer is part of the prompt, and a file larger than this is generated or binary.
    private const int MAX_MATCHES = 100;
    private const int MAX_FILE_BYTES = 1_000_000;
    // Because one long line, as in a minified file, would otherwise make a match as large as its file.
    private const int MAX_LINE_BYTES = 500;
    private const int MAX_ANSWER_BYTES = 100_000;

    /**
     * @param (Closure(int): void)|null $fileSearched
     */
    // The callback gets the number of files searched so far, because the MCP door turns it into progress.
    public function __construct(private Checkout $checkout, private ?Closure $fileSearched = null)
    {
    }

    public function __invoke(
        #[Schema(description: 'Text to find, matched literally and case-sensitively.')] string $text,
        #[Schema(description: 'File or directory to search, relative to the repository root; "." is the root.')] string $path = '.',
    ): string {
        if ('' === $text) {
            throw SearchTextEmpty::make();
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
        if (is_file($real)) {
            // The file the search names is refused when it cannot be read, as read_file refuses it, because "No match." would claim it was searched.
            yield from $this->matchesIn($real, $this->contentOf($real) ?? throw EntryNotReadable::because($this->checkout->relative($real), 'cannot be read'), $text);
            $this->fileSearched?->__invoke(1);

            return;
        }

        $searched = 0;

        foreach ($this->files($real) as $file) {
            // A file can go or become unreadable between the listing and its reading, so it is skipped like a binary one instead of failing the whole search.
            yield from $this->matchesIn($file->getPathname(), $this->contentOf($file->getPathname()) ?? '', $text);
            $this->fileSearched?->__invoke(++$searched);
        }
    }

    /**
     * @return Generator<SplFileInfo>
     */
    private function files(string $real): Generator
    {
        // Because a link in the code under review can point out of the checkout, symlinks are not followed.
        $entries = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            $this->directory($real),
            static fn (SplFileInfo $entry): bool => '.git' !== $entry->getFilename() && !$entry->isLink(),
        ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                yield $entry;
            }
        }
    }

    // Only the directory the search names can fail to open here, because CATCH_GET_CHILD skips an unreadable one below it.
    private function directory(string $real): RecursiveDirectoryIterator
    {
        try {
            return new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException $unopened) {
            throw EntryNotReadable::because($this->checkout->relative($real), 'cannot be searched', $unopened);
        }
    }

    /**
     * @return Generator<string>
     */
    private function matchesIn(string $path, string $content, string $text): Generator
    {
        // Because the answer reaches the provider as JSON, which carries only valid UTF-8, a binary file is not searched.
        if (strlen($content) > self::MAX_FILE_BYTES || !mb_check_encoding($content, 'UTF-8')) {
            return;
        }

        $relative = mb_scrub($this->checkout->relative($path), 'UTF-8');

        foreach ($this->lines($content) as $number => $line) {
            if (str_contains($line, $text)) {
                yield sprintf('%s:%d:%s', $relative, $number, $this->cut(rtrim($line, "\r")));
            }
        }
    }

    // Null for a file that is gone or unreadable, because the caller decides whether that refuses the search or skips the file.
    private function contentOf(string $path): ?string
    {
        set_error_handler(static fn (): bool => true);

        try {
            $content = file_get_contents($path, length: self::MAX_FILE_BYTES + 1);

            return false === $content ? null : $content;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return Generator<int, string>
     */
    private function lines(string $content): Generator
    {
        $start = 0;
        $number = 1;

        // Because a file of many short lines would otherwise hold them all at once, lines are cut out one at a time.
        while ($start <= strlen($content)) {
            $end = strpos($content, "\n", $start);
            $end = false === $end ? strlen($content) : $end;

            yield $number++ => substr($content, $start, $end - $start);

            $start = $end + 1;
        }
    }

    private function cut(string $line): string
    {
        return strlen($line) > self::MAX_LINE_BYTES ? mb_strcut($line, 0, self::MAX_LINE_BYTES, 'UTF-8').' [line cut]' : $line;
    }
}
