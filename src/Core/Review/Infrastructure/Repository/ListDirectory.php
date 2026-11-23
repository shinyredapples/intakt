<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\ReviewTool;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool(name: ReviewTool::ListDirectory->value, description: 'Lists the entries of one directory of the repository at the head under review; directories end with a slash.', effectHints: new ToolEffectHints(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
final readonly class ListDirectory
{
    // Because the answer is part of the prompt, and a vendored or generated directory can hold thousands of entries.
    private const int MAX_ANSWER_BYTES = 100_000;

    public function __construct(private Checkout $checkout)
    {
    }

    public function __invoke(#[Schema(description: 'Path of the directory, relative to the repository root; "." is the root.')] string $path = '.'): string
    {
        $real = $this->checkout->resolve($path);

        if (!is_dir($real)) {
            throw PathNotReadable::notADirectory($path);
        }

        $names = scandir($real);

        if (false === $names) {
            throw PathNotReadable::unlistable($path);
        }

        $entries = array_values(array_diff($names, ['.', '..', '.git']));
        sort($entries);

        return $this->listing($real, $entries);
    }

    /**
     * @param list<string> $entries
     */
    private function listing(string $real, array $entries): string
    {
        $lines = [];
        $bytes = 0;

        foreach ($entries as $entry) {
            // Scrubbed because the answer reaches the provider as JSON, which carries only valid UTF-8, and a committed name need not be.
            $line = mb_scrub($entry, 'UTF-8').(is_dir($real.'/'.$entry) ? '/' : '');
            $bytes += strlen($line) + 1;

            if ($bytes > self::MAX_ANSWER_BYTES) {
                return implode("\n", $lines)."\n[more entries not shown]";
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
