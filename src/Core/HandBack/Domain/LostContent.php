<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// A builder once replaced two documentation files with the single line "1", so a file that keeps under a fifth of its bytes and loses more than 50 lines fails the check.
final readonly class LostContent
{
    public const int FRACTION = 5;

    public const int MAX_LINES_LOST = 50;

    private function __construct(
        public string $path,
        public int $linesBefore,
        public int $linesAfter,
    ) {
    }

    public static function in(string $path, string $before, string $after): ?self
    {
        if (strlen($after) * self::FRACTION >= strlen($before)) {
            return null;
        }

        $lost = new self($path, self::linesOf($before), self::linesOf($after));

        return $lost->linesBefore - $lost->linesAfter > self::MAX_LINES_LOST ? $lost : null;
    }

    private static function linesOf(string $content): int
    {
        // A last line without its line break still counts, as an editor shows it.
        return substr_count($content, "\n") + ('' === $content || str_ends_with($content, "\n") ? 0 : 1);
    }
}
