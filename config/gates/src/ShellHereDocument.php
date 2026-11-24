<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ShellHereDocument
{
    public function __construct(private string $delimiter, private bool $quoted, private bool $tabsStripped)
    {
    }

    public function afterBody(string $source, int $offset): int
    {
        $length = strlen($source);

        while ($offset < $length) {
            [$line, $offset] = $this->lineAt($source, $offset, $length);

            if ($line === $this->delimiter) {
                break;
            }
        }

        return min($offset, $length);
    }

    /**
     * @return array{string, int}
     */
    private function lineAt(string $source, int $offset, int $length): array
    {
        $line = '';

        do {
            $end = $offset + strcspn($source, "\n", $offset);
            $piece = substr($source, $offset, $end - $offset);
            $joined = $end < $length && $this->joinsNextLine($piece);
            $line .= $joined ? substr($piece, 0, -1) : $piece;
            $offset = $end + 1;
        } while ($joined);

        return [$this->tabsStripped ? ltrim($line, "\t") : $line, $offset];
    }

    // Because bash removes a backslash-newline from an unquoted body before it looks for the delimiter, an odd run of backslashes at the line end joins the next line.
    private function joinsNextLine(string $piece): bool
    {
        return !$this->quoted && 1 === (strlen($piece) - strlen(rtrim($piece, '\\'))) % 2;
    }
}
