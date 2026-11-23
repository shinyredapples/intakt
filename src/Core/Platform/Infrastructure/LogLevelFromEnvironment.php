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
        $level = (string) $getEnv($name);

        if ('' === $level) {
            return self::DEFAULT_LEVEL;
        }

        $accepted = self::acceptedLevels();

        if (!in_array($level, $accepted, true)) {
            throw new InvalidArgumentException(sprintf('%s is "%s", which is not a log level: use one of %s.', self::variableOf($name), $level, implode(', ', $accepted)));
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
    private static function acceptedLevels(): array
    {
        return array_values(array_map(strval(...), new ReflectionClass(LogLevel::class)->getConstants()));
    }

    // The name arrives with the processors still to run, such as "default::INTAKT_LOG_LEVEL", and the message names the variable alone.
    private static function variableOf(string $name): string
    {
        return array_last(explode(':', $name));
    }
}
