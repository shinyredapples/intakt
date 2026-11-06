<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a child in a session of its own no longer gets the Ctrl-C or kill meant for bin/check.
final class StopSignalForwarding
{
    private const array STOPPING_SIGNALS = [1, 2, 15];

    /**
     * @param array<int, callable|int> $handlers
     */
    private function __construct(
        private readonly array $handlers,
    ) {
    }

    // Because the parent sleeps between polls of its child, a signal must run its handler at once, not at the next tick.
    public static function toGroup(int $group): self
    {
        if (!function_exists('pcntl_signal')) {
            return new self([]);
        }

        $handlers = [];
        pcntl_async_signals(true);

        foreach (self::STOPPING_SIGNALS as $signal) {
            $handlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function (int $signal) use ($group): void {
                posix_kill(-$group, 9);
                pcntl_signal($signal, SIG_DFL);
                posix_kill(posix_getpid(), $signal);
            });
        }

        return new self($handlers);
    }

    public function end(): void
    {
        foreach ($this->handlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
    }
}
