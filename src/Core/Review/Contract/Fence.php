<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Untrusted text must enter a prompt only through this class: docs/design/05-architecture/14-threat-model.md#third-party-text
final readonly class Fence
{
    public function wrap(UntrustedSource $source, string $text): FencedText
    {
        return new FencedText($source, $text);
    }

    // Only a fence this class made has content: the boundary must be the one the text inside gets, which no text can forge for itself.
    public function contentOf(UntrustedSource $source, string $fenced): ?string
    {
        if (1 !== preg_match('/\A<<<UNTRUSTED '.preg_quote($source->value, '/').' ('.FencedText::BOUNDARY_PREFIX.'[0-9a-f]{16})>>>\n/', $fenced, $opening)) {
            return null;
        }

        $content = substr($fenced, strlen($opening[0]), -strlen(sprintf("\n<<<END %s>>>", $opening[1])));

        return $this->wrap($source, $content)->rendered() === $fenced ? $content : null;
    }
}
