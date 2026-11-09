<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Untrusted text must enter a prompt only through this class: docs/design/05-architecture/14-threat-model.md#third-party-text
final readonly class Fence
{
    private const string BOUNDARY_PREFIX = 'fence-';

    public function wrap(UntrustedSource $source, string $text): string
    {
        $boundary = $this->boundaryFor($text);

        return sprintf("<<<UNTRUSTED %s %s>>>\n%s\n<<<END %s>>>", $source->value, $boundary, $text, $boundary);
    }

    // True only for exactly what wrap() gives for some text, so a reader that meets fenced text outside Intakt's own toolbox can tell Intakt's fence from a forged one.
    public function wraps(UntrustedSource $source, string $fenced): bool
    {
        $pattern = sprintf('/\A<<<UNTRUSTED %s (%s[0-9a-f]{16})>>>\n(.*)\n<<<END \1>>>\z/s', preg_quote($source->value, '/'), self::BOUNDARY_PREFIX);

        return 1 === preg_match($pattern, $fenced, $parts) && $this->wrap($source, $parts[2]) === $fenced;
    }

    // Derived from the text, not random, so the same text gives the same prompt and a provider's prompt cache still hits; the text cannot contain a hash of itself.
    private function boundaryFor(string $text): string
    {
        return self::BOUNDARY_PREFIX.substr(hash('sha256', $text), 0, 16);
    }
}
