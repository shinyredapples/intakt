<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool(name: 'list_directory', description: 'Lists the entries of one directory of the repository at the head under review; directories end with a slash.', effectHints: new ToolEffectHints(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
final readonly class ListDirectory
{
    public function __construct(private Checkout $checkout)
    {
    }

    public function __invoke(#[Schema(description: 'Path of the directory, relative to the repository root; "." is the root.')] string $path = '.'): string
    {
        $real = $this->checkout->resolve($path);

        if (!is_dir($real)) {
            throw new \RuntimeException(sprintf('"%s" is not a directory.', $path));
        }

        $entries = array_values(array_diff(scandir($real) ?: [], ['.', '..', '.git']));
        sort($entries);

        return implode("\n", array_map(static fn (string $entry): string => is_dir($real.'/'.$entry) ? $entry.'/' : $entry, $entries));
    }
}
