<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;

// A listener, not a prompt rule: the model's arguments are untrusted, so plain code refuses before any tool runs.
final readonly class DenyPathsOutsideCheckout
{
    // Every repository tool names its path parameter this way; the tests prove it for each tool.
    public const string PATH_ARGUMENT = 'path';

    public function __construct(private Checkout $checkout)
    {
    }

    public function __invoke(ToolCallRequested $event): void
    {
        $path = $event->getToolCall()->getArguments()[self::PATH_ARGUMENT] ?? null;

        if (null === $path) {
            return;
        }

        if (!is_string($path)) {
            $event->deny('The path must be a string.');

            return;
        }

        try {
            $this->checkout->resolve($path);
        } catch (PathOutsideCheckout $refused) {
            $event->deny($refused->getMessage());
        } catch (PathNotInCheckout) {
            // Missing is the tool's own failure to report, not an escape.
        }
    }
}
