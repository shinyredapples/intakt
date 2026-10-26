<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errors,
    ) {
    }

    public function succeeded(): bool
    {
        return 0 === $this->exitCode;
    }

    public function firstErrorLine(): string
    {
        $lines = preg_split('/\R/', trim($this->errors."\n".$this->output));

        return false === $lines ? '' : $lines[0];
    }
}
