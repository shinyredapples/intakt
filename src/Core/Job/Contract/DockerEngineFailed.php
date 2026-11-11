<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;
use Throwable;

final class DockerEngineFailed extends RuntimeException implements ReportableFailure
{
    public static function unreachable(string $socket, Throwable $previous): self
    {
        return new self(sprintf('The Docker Engine is not reachable at %s: %s', $socket, $previous->getMessage()), previous: $previous);
    }

    public static function noSocket(string $socket): self
    {
        return new self(sprintf('The Docker Engine is not reachable: there is no socket at %s.', $socket));
    }

    public static function refused(string $call, int $status, string $message): self
    {
        return new self(sprintf('The Docker Engine refused %s with status %d: %s', $call, $status, $message));
    }

    public static function notStoppedAfterKill(string $container, int $graceSeconds): self
    {
        return new self(sprintf('The container %s did not stop within %d seconds after the kill; no exit code is recorded.', $container, $graceSeconds));
    }

    public static function unexpected(string $call): self
    {
        return new self(sprintf('The Docker Engine answered %s with an unexpected body.', $call));
    }
}
