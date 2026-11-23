<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ShellHereDocuments
{
    private int $length;

    public function __construct(private string $source)
    {
        $this->length = strlen($source);
    }

    /**
     * @param array{delimiter: string, joinsLines: bool, stripsTabs: bool} $document
     */
    public function afterBody(int $offset, array $document): int
    {
        while ($offset < $this->length) {
            [$endsTheBody, $offset] = $this->lineAt($offset, $document);

            if ($endsTheBody) {
                return $offset;
            }
        }

        return $this->length;
    }

    /**
     * @param array{delimiter: string, joinsLines: bool, stripsTabs: bool} $document
     *
     * @return array{bool, int}
     */
    private function lineAt(int $offset, array $document): array
    {
        // Because only whether the line equals the delimiter matters, each joined part is compared with the delimiter where the last one stopped, never the whole line joined so far, so a long chain of joined lines under a long delimiter is read in linear time.
        $matched = 0;

        do {
            $end = $offset + strcspn($this->source, "\n", $offset);
            $start = 0 === $matched && $document['stripsTabs'] ? $offset + strspn($this->source, "\t", $offset, $end - $offset) : $offset;
            $continues = $document['joinsLines'] && $end < $this->length && $this->endsInAnEscape($start, $end);
            $matched = $this->matchedAfter($document['delimiter'], $matched, substr($this->source, $start, $end - $start - ($continues ? 1 : 0)));
            $offset = $end + 1;
        } while ($continues);

        return [strlen($document['delimiter']) === $matched, min($offset, $this->length)];
    }

    // Because a backslash escapes the next one, only an odd run of backslashes before the line end joins the next line.
    private function endsInAnEscape(int $start, int $end): bool
    {
        $line = substr($this->source, $start, $end - $start);

        return 1 === (strlen($line) - strlen(rtrim($line, '\\'))) % 2;
    }

    // Because a line that already differs from the delimiter can never equal it, it is no longer compared.
    private function matchedAfter(string $delimiter, ?int $matched, string $part): ?int
    {
        if (null === $matched || $matched + strlen($part) > strlen($delimiter)) {
            return null;
        }

        $same = '' === $part || 0 === substr_compare($delimiter, $part, $matched, strlen($part));

        return $same ? $matched + strlen($part) : null;
    }
}
