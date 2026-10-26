<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class LongDashLines
{
    private const array CODE_POINTS = ["\u{2014}" => 'U+2014', "\u{2015}" => 'U+2015', "\u{2E3A}" => 'U+2E3A'];

    public function __construct(private string $text)
    {
    }

    /**
     * @return array<int, string>
     */
    public function codePointsByLineNumber(): array
    {
        $found = [];

        foreach (explode("\n", $this->text) as $index => $line) {
            $codePoints = $this->codePointsIn($line);

            if ('' !== $codePoints) {
                $found[$index + 1] = $codePoints;
            }
        }

        return $found;
    }

    private function codePointsIn(string $line): string
    {
        $present = array_filter(self::CODE_POINTS, static fn (string $codePoint, string $dash): bool => str_contains($line, $dash), ARRAY_FILTER_USE_BOTH);

        return implode(', ', $present);
    }
}
