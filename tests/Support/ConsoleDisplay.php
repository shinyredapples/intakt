<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Util\Exporter;

// Because SymfonyStyle wraps a block at the terminal width (120 columns at most, so no COLUMNS keeps a long path whole), cuts inside a word and drops the run of spaces it breaks at, a display is walked in plain PHP allowing a break between any two characters: a pattern with one group per character stops compiling at about 800 characters.
final class ConsoleDisplay extends Constraint
{
    private const string BREAK = "\u{E000}";

    // A run of spaces stays one token because one break stands for the whole run.
    /** @var list<string> */
    private readonly array $tokens;

    private function __construct(private readonly string $text)
    {
        $parts = preg_split('/( +)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if (false === $parts) {
            throw new InvalidArgumentException('Not valid UTF-8: '.$text);
        }
        $this->tokens = array_merge(...array_map(
            static fn (string $part): array => str_starts_with($part, ' ') ? [$part] : mb_str_split($part),
            $parts,
        ));
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
        foreach ($this->tokens as $token) {
            $position = $this->positionAfter($token, $display, $position);

            if (null === $position) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $display */
    private function positionAfter(string $token, array $display, int $position): ?int
    {
        $shown = $display[$position] ?? null;

        if (str_starts_with($token, ' ') && self::BREAK === $shown) {
            return $position + 1;
        }

        if (self::BREAK === $shown) {
            ++$position;
        }

        $length = mb_strlen($token);

        return implode('', array_slice($display, $position, $length)) === $token ? $position + $length : null;
    }
}
