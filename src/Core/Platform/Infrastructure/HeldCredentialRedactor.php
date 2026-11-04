<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Infrastructure;

use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Contract\Redactor;
use NoDiscard;
use Override;

final readonly class HeldCredentialRedactor implements Redactor
{
    public function __construct(
        private Redaction $redaction,
        private HeldCredentials $heldCredentials,
    ) {
    }

    // The values are read at each call, as the log formatter reads them, because they come from the environment and the vault when the text is written, not when the container is built.
    #[Override]
    #[NoDiscard]
    public function redact(string $text): string
    {
        return $this->redaction->redact($text, ...$this->heldCredentials->values());
    }
}
