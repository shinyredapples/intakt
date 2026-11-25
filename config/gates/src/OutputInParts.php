<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Generator;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

final readonly class OutputInParts
{
    public function __construct(private Process $process)
    {
    }

    /**
     * @return Generator<int, string>
     *
     * @throws UnexpectedValueException
     */
    // Because the reader may stop early, the process ends with the generator, read to its end or not.
    public function read(): Generator
    {
        try {
            $this->process->start();

            foreach ($this->process->getIterator(Process::ITER_SKIP_ERR) as $part) {
                yield strval($part);
            }
        } catch (ExceptionInterface $exception) {
            throw new UnexpectedValueException('the process failed', $exception->getCode(), previous: $exception);
        } finally {
            $this->process->stop(0);
        }

        if (!$this->process->isSuccessful()) {
            throw new UnexpectedValueException('the process failed');
        }
    }
}
