<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool(name: 'search_files', description: 'Finds the lines of the repository at the head under review that contain a text; answers path:line:text.', effectHints: new ToolEffectHints(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
final readonly class SearchFiles
{
    // Because the answer is part of the prompt, and a file larger than this is generated or binary.
    private const int MAX_MATCHES = 100;
    private const int MAX_FILE_BYTES = 1_000_000;

    public function __construct(private Checkout $checkout)
    {
    }

    public function __invoke(
        #[Schema(description: 'Text to find, matched literally and case-sensitively.')] string $text,
        #[Schema(description: 'File or directory to search, relative to the repository root; "." is the root.')] string $path = '.',
    ): string {
        if ('' === $text) {
            throw new \InvalidArgumentException('The text to find is empty.');
        }

        $matches = [];

        foreach ($this->files($this->checkout->resolve($path)) as $file) {
            array_push($matches, ...$this->matchesIn($file, $text));

            if (count($matches) >= self::MAX_MATCHES) {
                return implode("\n", array_slice($matches, 0, self::MAX_MATCHES))."\n[more matches not shown]";
            }
        }

        return [] === $matches ? 'No match.' : implode("\n", $matches);
    }

    /**
     * @return \Generator<string>
     */
    private function files(string $real): \Generator
    {
        if (is_file($real)) {
            yield $real;

            return;
        }

        // Symlinks are not followed: a link in the code under review can point out of the checkout.
        $entries = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $entry): bool => '.git' !== $entry->getFilename() && !$entry->isLink(),
        ));

        foreach ($entries as $entry) {
            if ($entry->isFile() && $entry->getSize() <= self::MAX_FILE_BYTES) {
                yield $entry->getPathname();
            }
        }
    }

    /**
     * @return list<string>
     */
    private function matchesIn(string $file, string $text): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $relative = $this->checkout->relative($file);
        $matches = [];

        foreach ($lines as $number => $line) {
            if (str_contains($line, $text)) {
                $matches[] = sprintf('%s:%d:%s', $relative, $number + 1, $line);
            }
        }

        return $matches;
    }
}
