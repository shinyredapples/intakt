<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\FencedText;
use Intakt\Core\Review\Contract\UntrustedSource;

// A constant, not an option: no caller needs another ceiling, and a fixed one keeps the cached prompt prefix and the tests stable.
final readonly class PromptCut
{
    public const int CEILING_BYTES = 100_000;

    public function __construct(private Fence $fence)
    {
    }

    public function fenced(UntrustedSource $source, string $text): FencedText
    {
        $head = $this->head($text);
        $fenced = $this->fence->wrap($source, $head);

        return $this->cuts($text) ? $fenced->cut(strlen($head), strlen($text)) : $fenced;
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
}
