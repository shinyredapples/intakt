<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Util\Exporter;

// Because SymfonyStyle wraps a block at the terminal width (120 columns at most, so no COLUMNS keeps a long path whole), cuts inside a word and drops a space it breaks at, a display is walked in plain PHP allowing a break between any two characters: a pattern with one group per character stops compiling at about 800 characters.
final class ConsoleDisplay extends Constraint
{
    private const string BREAK = "\u{E000}";

    /** @var list<string> */
    private readonly array $characters;

    private function __construct(private readonly string $text)
    {
        $this->characters = mb_str_split($text);
    }

    public static function shows(string $text): self
    {
        return new self($text);
    }

    public function toString(): string
    {
        return 'shows '.Exporter::export($this->text).', wrapped or not';
    }

    protected function matches(mixed $other): bool
    {
        if (!is_string($other)) {
            return false;
        }

        $display = mb_str_split((string) preg_replace('/[ \t]*\R[ \t]*/u', self::BREAK, $other));

        return array_any(array_keys($display), fn ($start) => $this->isShownAt($display, $start));
    }

    /** @param list<string> $display */
    private function isShownAt(array $display, int $position): bool
    {
        $count = count($this->characters);

        for ($index = 0; $index < $count; ++$index) {
            $character = $this->characters[$index];
            $shown = $display[$position] ?? null;

            // A break drops every space of the run it lands in, so it stands for the whole run.
            if (' ' === $character && self::BREAK === $shown) {
                while (' ' === ($this->characters[$index + 1] ?? null)) {
                    ++$index;
                }
            } elseif (0 < $index && self::BREAK === $shown && ($display[$position + 1] ?? null) === $character) {
                ++$position;
            } elseif ($shown !== $character) {
                return false;
            }

            ++$position;
        }

        return true;
    }
}
