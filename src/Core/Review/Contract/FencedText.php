<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The fence is rendered here, so every value of this type wraps its content in markers whose boundary that content cannot forge: docs/design/05-architecture/14-threat-model.md#third-party-text
final readonly class FencedText
{
    public const string BOUNDARY_PREFIX = 'fence-';

    private const string CUT_NOTE = "\n\nIntakt cut this text: %d of %d bytes are shown and the rest is left out. Read what you need with your tools; an approval of a review with a cut text is refused.";

    private const string OMITTED_NOTE = "\n\nIntakt cut this text: %d of %d bytes are shown and the rest is left out.";

    private string $fence;

    private string $note;

    public function __construct(UntrustedSource $source, string $content)
    {
        $boundary = $this->boundaryFor($content);
        $this->fence = sprintf("<<<UNTRUSTED %s %s>>>\n%s\n<<<END %s>>>", $source->value, $boundary, $content, $boundary);
        $this->note = '';
    }

    // The note stays outside the fence because it is Intakt's own text; inside, the reviewer would have to ignore it as data.
    public function cut(int $shownBytes, int $allBytes): self
    {
        return clone ($this, ['note' => sprintf(self::CUT_NOTE, $shownBytes, $allBytes)]);
    }

    // For a reader with no tools, which cannot be told to read the rest itself.
    public function omitted(int $shownBytes, int $allBytes): self
    {
        return clone ($this, ['note' => sprintf(self::OMITTED_NOTE, $shownBytes, $allBytes)]);
    }

    public function rendered(): string
    {
        return $this->fence.$this->note;
    }

    // Derived from the text, not random, so the same text gives the same prompt and a provider's prompt cache still hits; the text cannot contain a hash of itself.
    private function boundaryFor(string $content): string
    {
        return self::BOUNDARY_PREFIX.substr(hash('sha256', $content), 0, 16);
    }
}
