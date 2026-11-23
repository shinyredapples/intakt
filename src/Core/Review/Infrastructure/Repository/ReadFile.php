<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\ReviewTool;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool(name: ReviewTool::ReadFile->value, description: 'Reads one file of the repository at the head under review.', effectHints: new ToolEffectHints(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
final readonly class ReadFile
{
    // Because a tool answer is part of the prompt: a whole generated file would crowd out the review.
    private const int MAX_BYTES = 100_000;
    private const int CHUNK_BYTES = 1_048_576;

    public function __construct(private Checkout $checkout)
    {
    }

    public function __invoke(#[Schema(description: 'Path of the file, relative to the repository root.')] string $path): string
    {
        return $this->contentAt($this->checkout->resolve($path), $path);
    }

    // Takes the path the checkout resolved, so a caller that records the file reads the very file it records; the asked path is the one a refusal names.
    public function contentAt(string $real, string $path): string
    {
        if (!is_file($real)) {
            throw PathNotReadable::notAFile($path);
        }

        $content = file_get_contents($real, length: self::MAX_BYTES + 1);

        if (false === $content) {
            throw PathNotReadable::unreadable($path);
        }

        // Named by its size, because the answer reaches the provider as JSON, which carries only valid UTF-8, and a binary file holds nothing to review.
        if ($this->holdsANulByte($real, $path)) {
            return sprintf('Binary file, not shown: %d bytes', $this->sizeOf($real, $path));
        }

        $truncated = strlen($content) > self::MAX_BYTES;
        // Cut on a character boundary, since half a character is not valid UTF-8.
        $text = $truncated ? mb_strcut($content, 0, self::MAX_BYTES, 'UTF-8') : $content;

        // Because the answer reaches the provider as JSON, which carries only valid UTF-8.
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw PathNotReadable::notText($path);
        }

        return $truncated ? $text."\n[truncated]" : $text;
    }

    // The whole file, not only the part an answer shows, because the diff names a file binary by a NUL byte anywhere and a read must name it the same.
    private function holdsANulByte(string $real, string $path): bool
    {
        $stream = fopen($real, 'rb');

        if (false === $stream) {
            throw PathNotReadable::unreadable($path);
        }

        try {
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);

                if (false === $chunk) {
                    throw PathNotReadable::unreadable($path);
                }

                if (str_contains($chunk, "\0")) {
                    return true;
                }
            }

            return false;
        } finally {
            fclose($stream);
        }
    }

    private function sizeOf(string $real, string $path): int
    {
        $size = filesize($real);

        return false === $size ? throw PathNotReadable::unreadable($path) : $size;
    }
}
