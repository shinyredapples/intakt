<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// One paragraph of a prompt put together by PromptAssembly::document(): the lead is Intakt's own literal and anything else only fenced, so no runtime string reaches the prompt: docs/design/05-architecture/14-threat-model.md#third-party-text
final readonly class PromptPart
{
    /**
     * @param literal-string $lead
     */
    public function __construct(
        private string $lead,
        private ?FencedText $body = null,
    ) {
    }

    public function rendered(): string
    {
        if (!$this->body instanceof FencedText) {
            return $this->lead;
        }

        return $this->lead."\n\n".$this->body->rendered();
    }
}
