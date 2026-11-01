<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, array<mixed>}> */
    public array $logged = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->logged[] = [(string) $message, $context];
    }
}
