<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use RuntimeException;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool(name: 'read_file', description: 'Reads one file of the repository at the head under review.', effectHints: new ToolEffectHints(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
final readonly class ReadFile
{
    // Because a tool answer is part of the prompt: a whole generated file would crowd out the review.
    private const int MAX_BYTES = 100_000;

    public function __construct(private Checkout $checkout)
    {
    }

    public function __invoke(#[Schema(description: 'Path of the file, relative to the repository root.')] string $path): string
    {
        $real = $this->checkout->resolve($path);

        if (!is_file($real)) {
            throw new RuntimeException(sprintf('"%s" is not a file.', $path));
        }

        $content = file_get_contents($real, length: self::MAX_BYTES + 1);

        if (false === $content) {
            throw new RuntimeException(sprintf('"%s" cannot be read.', $path));
        }

        return strlen($content) > self::MAX_BYTES ? substr($content, 0, self::MAX_BYTES)."\n[truncated]" : $content;
    }
}
