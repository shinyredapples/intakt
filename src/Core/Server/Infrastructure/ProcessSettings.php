<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

// FrankenPHP's worker mode keeps umask(), the locale and $_ENV for the whole process, so a request would otherwise start with what the request before changed.
final readonly class ProcessSettings
{
    /**
     * @param array<mixed> $env
     */
    public function __construct(
        public int $umask,
        public string $locale,
        public array $env,
    ) {
    }

    // Reading the locale with "0" cannot fail; "C" only satisfies the type, because an empty string would set the locale from the environment instead.
    public static function now(): self
    {
        return new self(umask(), setlocale(LC_ALL, '0') ?: 'C', $_ENV);
    }

    public function restore(): void
    {
        umask($this->umask);
        setlocale(LC_ALL, $this->locale);
        $_ENV = $this->env;
    }
}
