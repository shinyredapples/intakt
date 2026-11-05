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
            self::iniSetting(...),
        );
    }

    private static function iniSetting(string $name): ?string
    {
        // Without an ini file that sets it, ini_get() returns '' while PHP applies E_ALL.
        if ('error_reporting' === $name) {
            return (string) error_reporting();
        }

        $value = ini_get($name);

        return false === $value ? null : $value;
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
