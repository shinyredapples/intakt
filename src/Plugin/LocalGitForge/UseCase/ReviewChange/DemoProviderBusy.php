<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Reportable, so intakt:demo prints the message and fails instead of waiting minutes in silence for a rate-limited reviewer (issue #2802).
final class DemoProviderBusy extends RuntimeException implements ReportableFailure
{
    public static function for(string $waitedOn, Provider $provider, int $seconds): self
    {
        return new self(sprintf(
            'The %s cannot get an answer: %s is rate-limited for %d s and no fallback is free. Run intakt:demo again in a few minutes, or review with a key of another AI lab (%s or %s, see "The demo with docker compose" in docs/reference/user-manual.md).',
            $waitedOn,
            $provider->value,
            $seconds,
            implode(', ', [...Provider::Anthropic->variables()]),
            implode(', ', [...Provider::OpenAi->variables()]),
        ));
    }
}
