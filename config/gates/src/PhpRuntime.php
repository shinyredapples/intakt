<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Closure;

final readonly class PhpRuntime
{
    /**
     * @param list<string>             $extensions
     * @param Closure(string): ?string $setting
     */
    public function __construct(
        public string $version,
        public array $extensions,
        private Closure $setting,
    ) {
    }

    public static function current(): self
    {
        return new self(
            PHP_VERSION,
            array_map(strtolower(...), get_loaded_extensions()),
            static fn (string $name): ?string => false === ini_get($name) ? null : ini_get($name),
        );
    }

    public function hasExtension(string $name): bool
    {
        return in_array(strtolower($name), $this->extensions, true);
    }

    public function setting(string $name): ?string
    {
        return ($this->setting)($name);
    }
}
