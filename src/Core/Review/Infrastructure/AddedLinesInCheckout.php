<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\CheckoutNotADirectory;

// Gives each line the diff adds with its file, its number and its redacted text as it stands in the checked-out file, because a credential of several lines (a private key's body under a BEGIN line outside the hunk) is recognized only in its whole file.
final readonly class AddedLinesInCheckout
{
    public function __construct(private DiffAddedLines $addedLines, private Redactor $redactor)
    {
    }

    /**
     * @return list<array{string, int, string}>
     */
    public function of(string $diff, string $checkoutRoot): array
    {
        try {
            $checkout = new Checkout($checkoutRoot);
        } catch (CheckoutNotADirectory) {
            // No line when the checkout is gone, because the diff alone cannot be redacted with its files. Caught, not checked first, because the checkout may go between a check and the read.
            return [];
        }

        $linesOfFiles = [];
        $found = [];

        foreach ($this->addedLines->in($diff) as [$path, $number]) {
            $linesOfFiles[$path] ??= $this->redactedLinesOf($checkout, $path);
            $text = $linesOfFiles[$path][$number - 1] ?? null;

            if (null !== $text) {
                $found[] = [$path, $number, $text];
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function redactedLinesOf(Checkout $checkout, string $path): array
    {
        $content = CheckedOutFile::contentBehindNoLinkOf($checkout, $path);
        if (null === $content) {
            return [];
        }

        // The bytes as they are, carriage returns included, so a held credential holding CRLF still matches.
        $redacted = $this->redactor->redact($content);

        // No line of the file when a redacted part took a line break away, because its lines are then no longer the file's.
        if (substr_count($redacted, "\n") !== substr_count($content, "\n")) {
            return [];
        }

        return array_map(static fn (string $line): string => str_ends_with($line, "\r") ? substr($line, 0, -1) : $line, explode("\n", $redacted));
    }
}
