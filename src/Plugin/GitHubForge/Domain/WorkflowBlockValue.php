<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowBlockValue
{
    /**
     * @param list<string> $lines
     *
     * @return array{WorkflowScalar, int}
     */
    public function read(array $lines, WorkflowLine $header): array
    {
        $indent = $this->textIndent($lines, $header);
        $texts = [];
        $next = $header->number;
        while (null !== $indent && $next < count($lines) && ($this->isBlank($lines[$next]) || $this->lead($lines[$next]) >= $indent)) {
            $texts[] = substr($lines[$next], $indent);
            ++$next;
        }

        // A line between the key's indentation and the text's would end the block for one reader and fail for another, so its extent is uncertain.
        if (null !== $indent && $next < count($lines) && $this->lead($lines[$next]) > $header->indent) {
            throw new WorkflowFormNotRead($next + 1, 'a line of a block value indented less than its first line');
        }

        return [new WorkflowScalar($header->number, implode("\n", $texts)), $next];
    }

    /**
     * @param list<string> $lines
     */
    private function textIndent(array $lines, WorkflowLine $header): ?int
    {
        $widestBlank = 0;
        // Starts at the header's line number because it counts from one, which makes it the index of the line after the header.
        for ($index = $header->number; $index < count($lines); ++$index) {
            $lead = $this->lead($lines[$index]);
            if ($this->isBlank($lines[$index])) {
                $widestBlank = max($widestBlank, $lead);

                continue;
            }

            if ($lead <= $header->indent) {
                return null;
            }

            if ($widestBlank > $lead) {
                throw new WorkflowFormNotRead($index + 1, 'a block value whose leading blank lines are indented deeper than its text');
            }

            return $lead;
        }

        return null;
    }

    private function isBlank(string $line): bool
    {
        return '' === trim($line, " \t");
    }

    private function lead(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }
}
