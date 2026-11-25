<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Generator;
use UnexpectedValueException;

final readonly class OutputInParts
{
    public const int PART_BYTES = 65_536;

    private const int KILL = 9;

    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     */
    public function __construct(private array $command, private string $directory, private array $environment, private int $seconds)
    {
    }

    /**
     * @return Generator<int, string>
     *
     * @throws UnexpectedValueException
     */
    // Because Symfony's Process hands over all a fast writer printed as one part, the pipe is read here in parts of a fixed size; because the reader may stop early, the process ends with the generator, read to its end or not.
    public function read(): Generator
    {
        $pipes = [];
        $process = @proc_open($this->commandWithItsVariables(), [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes, $this->directory);

        if (!is_resource($process)) {
            throw new UnexpectedValueException('the process did not start');
        }

        $ended = false;

        try {
            yield from $this->partsOf($pipes[1]);
            $ended = true;
        } finally {
            fclose($pipes[1]);

            if (!$ended) {
                proc_terminate($process, self::KILL);
            }

            $exitCode = proc_close($process);
        }

        if (0 !== $exitCode) {
            throw new UnexpectedValueException('the process failed');
        }
    }

    /**
     * @return list<string>
     */
    // Because proc_open() leaves a variable with an empty value out, which would break the hardened call, env sets the variables and then becomes the command.
    private function commandWithItsVariables(): array
    {
        $unset = [];
        $set = [];

        foreach ($this->environment as $name => $value) {
            if (false === $value) {
                $unset = [...$unset, '-u', $name];
            } else {
                $set[] = $name.'='.$value;
            }
        }

        return ['env', ...$unset, ...$set, ...$this->command];
    }

    /**
     * @param resource $pipe
     *
     * @return Generator<int, string>
     *
     * @throws UnexpectedValueException
     */
    private function partsOf(mixed $pipe): Generator
    {
        stream_set_blocking($pipe, false);
        $deadline = hrtime(true) + ($this->seconds * 1_000_000_000);

        while (!feof($pipe)) {
            if (hrtime(true) >= $deadline) {
                throw new UnexpectedValueException('the process ran too long');
            }

            $readable = [$pipe];
            $none = null;

            // Because a signal interrupts the wait, a failed one only asks again until the deadline.
            if (1 !== @stream_select($readable, $none, $none, 1)) {
                continue;
            }

            $part = fread($pipe, self::PART_BYTES);

            if (is_string($part) && '' !== $part) {
                yield $part;
            }
        }
    }
}
