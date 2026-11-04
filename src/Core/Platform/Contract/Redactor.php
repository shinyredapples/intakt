<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use NoDiscard;

// Text that is stored or shown is redacted as a log line is, the credentials this install holds included, because a configured key with no recognizable shape would otherwise pass the shape-only Redaction.
interface Redactor
{
    #[NoDiscard]
    public function redact(string $text): string;
}
