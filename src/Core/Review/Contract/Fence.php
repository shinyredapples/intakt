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

    // Only a fence this class made has content: the boundary must be the one the text inside gets, which no text can forge for itself.
    public function contentOf(UntrustedSource $source, string $fenced): ?string
    {
        if (1 !== preg_match('/\A<<<UNTRUSTED '.preg_quote($source->value, '/').' ('.self::BOUNDARY_PREFIX.'[0-9a-f]{16})>>>\n/', $fenced, $opening)) {
            return null;
        }

        $content = substr($fenced, strlen($opening[0]), -strlen(sprintf("\n<<<END %s>>>", $opening[1])));

        return $this->wrap($source, $content) === $fenced ? $content : null;
    }

    // Derived from the text, not random, so the same text gives the same prompt and a provider's prompt cache still hits; the text cannot contain a hash of itself.
    private function boundaryFor(string $text): string
    {
        return self::BOUNDARY_PREFIX.substr(hash('sha256', $text), 0, 16);
    }
}
