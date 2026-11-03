<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\UntrustedSource;

// A constant, not an option: no caller needs another ceiling, and a fixed one keeps the cached prompt prefix and the tests stable.
final readonly class PromptCut
{
    public const int CEILING_BYTES = 100_000;

    public function __construct(private Fence $fence)
    {
    }

    // The note stays outside the fence because it is Intakt's own text; inside, the reviewer would have to ignore it as data.
    public function fenced(UntrustedSource $source, string $text): string
    {
        $fenced = $text
            |> $this->head(...)
            |> (fn (string $head): string => $this->fence->wrap($source, $head));

        return $fenced.$this->noteFor($text);
    }

    public function cuts(string $text): bool
    {
        return strlen($text) > self::CEILING_BYTES;
    }

    // Cutting by bytes at a character boundary, so a multibyte character is never split.
    private function head(string $text): string
    {
        return mb_strcut($text, 0, self::CEILING_BYTES, 'UTF-8');
    }

    private function noteFor(string $text): string
    {
        if (!$this->cuts($text)) {
            return '';
        }

        return sprintf(
            "\n\nIntakt cut this text: %d of %d bytes are shown and the rest is left out. Read what you need with your tools; an approval of a review with a cut text is refused.",
            strlen($this->head($text)),
            strlen($text),
        );
    }
}
