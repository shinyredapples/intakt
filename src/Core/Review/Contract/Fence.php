<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The one way untrusted text enters a prompt (threat model, "Third-party text").
final readonly class Fence
{
    private const string BOUNDARY_PREFIX = 'fence-';

    public function wrap(UntrustedSource $source, string $text): string
    {
        $boundary = $this->boundaryFor($text);

        return sprintf("<<<UNTRUSTED %s %s>>>\n%s\n<<<END %s>>>", $source->value, $boundary, $text, $boundary);
    }

    // Derived from the text, not random, so the same text gives the same prompt and a provider's prompt cache still hits; the text cannot contain a hash of itself.
    private function boundaryFor(string $text): string
    {
        $boundary = $this->hashed($text);

        while (str_contains($text, $boundary)) {
            $boundary = $this->hashed($boundary);
        }

        return $boundary;
    }

    private function hashed(string $value): string
    {
        return self::BOUNDARY_PREFIX.substr(hash('sha256', $value), 0, 16);
    }
}
