<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Exception\InvalidToolCallArgumentsException;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;

// A listener, not a prompt rule: the model's arguments are untrusted, so plain code refuses before any tool runs.
final readonly class DenyPathsOutsideCheckout
{
    // Because the listener reads this one argument for all three tools, a test checks each tool's parameters for it.
    public const string PATH_ARGUMENT = 'path';

    public function __construct(private Checkout $checkout, private ToolCallArgumentResolverInterface $arguments)
    {
    }

    public function __invoke(ToolCallRequested $event): void
    {
        $path = $event->getToolCall()->getArguments()[self::PATH_ARGUMENT] ?? null;

        if (null === $path) {
            return;
        }

        // Thrown as an invalid argument rather than denied, because the toolbox's resolver would coerce a wrong type and the reviewer counts a malformed call as changes; a denial is the answer to an escape.
        if (!is_string($path)) {
            throw new InvalidToolCallArgumentsException('The path must be a string.');
        }

        // Resolved here because a denial stops the toolbox before its own resolver runs, which would let a call missing another argument pass as a plain denial instead of a malformed call.
        $this->arguments->resolveArguments($event->getDefinition(), $event->getToolCall());

        try {
            $this->checkout->resolve($path);
        } catch (PathOutsideCheckout $refused) {
            $event->deny($refused->getMessage());
        } catch (PathNotInCheckout) {
            // Because a missing path is the tool's failure to report, not an escape.
        }
    }
}
