<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = $message.json_encode($context);
    }
}
