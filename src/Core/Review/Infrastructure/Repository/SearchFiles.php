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
            throw SearchTextEmpty::create();
        }

        $matches = [];
        $bytes = 0;

        foreach ($this->matches($this->checkout->resolve($path), $text, $path) as $match) {
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
    private function matches(string $real, string $text, string $path): Generator
    {
        $searched = 0;

        foreach ($this->files($real, $path) as $file) {
            yield from $this->matchesIn($file, $text);
            $this->fileSearched?->__invoke(++$searched);
        }
    }

    /**
     * @return Generator<SplFileInfo>
     */
    private function files(string $real, string $path): Generator
    {
        if (is_file($real)) {
            yield new SplFileInfo($real);

            return;
        }

        // Because a link in the code under review can point out of the checkout, symlinks are not followed.
        $entries = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            $this->directory($real, $path),
            static fn (SplFileInfo $entry): bool => '.git' !== $entry->getFilename() && !$entry->isLink(),
        ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                yield $entry;
            }
        }
    }

    // A named refusal, because the reviewed code chose the path: a pipe or an unopenable directory is its doing, not a failure of the server.
    private function directory(string $real, string $path): RecursiveDirectoryIterator
    {
        try {
            return new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException $unopened) {
            throw PathNotReadable::unsearchable($path, $unopened);
        }
    }

    /**
     * @return Generator<string>
     */
    private function matchesIn(SplFileInfo $file, string $text): Generator
    {
        $relative = mb_scrub($this->checkout->relative($file->getPathname()), 'UTF-8');
        $content = $this->sizeOf($file, $relative) <= self::MAX_FILE_BYTES ? file_get_contents($file->getPathname()) : false;

        // Because the answer reaches the provider as JSON, which carries only valid UTF-8, a binary file is not searched.
        if (false === $content || !mb_check_encoding($content, 'UTF-8')) {
            return;
        }

        foreach ($this->lines($content) as $number => $line) {
            if (str_contains($line, $text)) {
                yield sprintf('%s:%d:%s', $relative, $number, $this->cut(rtrim($line, "\r")));
            }
        }
    }

    // A named refusal, because a file removed after the walk found it is the reviewed tree changing under the search, not a failure of the server.
    private function sizeOf(SplFileInfo $file, string $relative): int
    {
        // Silenced, because a handler that throws warnings (a debug kernel) would otherwise turn the failed stat into an ErrorException before the refusal below.
        $size = @filesize($file->getPathname());

        return false === $size ? throw PathNotReadable::unreadable($relative) : $size;
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
