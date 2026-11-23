<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Infrastructure;

use Closure;
use InvalidArgumentException;
use Psr\Log\LogLevel;
use ReflectionClass;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

// Checks the level itself, because Symfony's Logger refuses an unknown one without naming the levels it accepts.
final readonly class LogLevelFromEnvironment implements EnvVarProcessorInterface
{
    private const string DEFAULT_LEVEL = LogLevel::WARNING;

    public function getEnv(string $prefix, string $name, Closure $getEnv): string
    {
        $value = $getEnv($name);

        if (null === $value || '' === $value) {
            return self::DEFAULT_LEVEL;
        }

        $accepted = $this->acceptedLevels();
        $level = array_find($accepted, static fn (string $level): bool => $level === $value);

        if (null === $level) {
            throw new InvalidArgumentException(sprintf('%s is "%s", which is not a log level: use one of %s.', $this->variableOf($name), is_scalar($value) ? $value : get_debug_type($value), implode(', ', $accepted)));
        }

        return $level;
    }

    public static function getProvidedTypes(): array
    {
        return ['log_level' => 'string'];
    }

    /**
     * @return list<string>
     */
    private function acceptedLevels(): array
    {
        return array_values(array_filter(new ReflectionClass(LogLevel::class)->getConstants(), is_string(...)));
    }

    // Needed because the name arrives with the processors still to run, such as "default::INTAKT_LOG_LEVEL", and the reader looks for the variable alone.
    private function variableOf(string $name): string
    {
        return array_last(explode(':', $name));
    }
}
