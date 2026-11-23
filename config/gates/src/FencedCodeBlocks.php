<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class FencedCodeBlocks
{
    // Matched before trim(), because CommonMark reads four spaces of indentation as code and a backtick in the info string as inline code.
    private const string OPENING_FENCE = '/^ {0,3}(`{3,}(?=[^`]*$)|~{3,})/';

    // CommonMark: a closing fence carries no info string, so a fence line with trailing text stays inside the block.
    private const string CLOSING_FENCE = '/^ {0,3}(`{3,}|~{3,})\s*$/';

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public function blanked(array $lines): array
    {
        $fence = null;

        foreach ($lines as $index => $line) {
            $inside = null !== $fence;
            $fence = $this->fenceAfter($line, $fence);

            // A table in a code block is an example; blanking keeps line numbers and ends any table around the block.
            if ($inside || null !== $fence) {
                $lines[$index] = '';
            }
        }

        return $lines;
    }

    public function fenceAfter(string $line, ?string $fence): ?string
    {
        if (null === $fence) {
            return 1 === preg_match(self::OPENING_FENCE, $line, $marker) ? $marker[1] : null;
        }

        if (1 !== preg_match(self::CLOSING_FENCE, $line, $marker)) {
            return $fence;
        }

        return $this->closes($marker[1], $fence) ? null : $fence;
    }

    private function closes(string $marker, string $fence): bool
    {
        // CommonMark: a fence closes only on its own character and at least its own length, so a longer block can show a shorter fence.
        return $marker[0] === $fence[0] && strlen($marker) >= strlen($fence);
    }
}
