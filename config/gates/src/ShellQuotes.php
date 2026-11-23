<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ShellQuotes
{
    private int $length;

    public function __construct(private string $source)
    {
        $this->length = strlen($source);
    }

    public function endOfSingleQuoted(int $offset): int
    {
        $close = strpos($this->source, "'", $offset);

        return false === $close ? $this->length : $close + 1;
    }

    // Because a backslash escapes the quote in `"..."` and in `$'...'`, the string ends only at an unescaped one.
    public function endOfEscaped(string $quote, int $offset): int
    {
        while (($offset += strcspn($this->source, $quote.'\\', $offset)) < $this->length) {
            if ($quote === $this->source[$offset]) {
                return $offset + 1;
            }

            $offset += 2;
        }

        return $this->length;
    }
}
