<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Infrastructure;

use Closure;
use InvalidArgumentException;
use Psr\Log\LogLevel;
use ReflectionClass;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

// Checks the level itself, because Symfony's Logger refuses an unknown one without naming the levels it accepts.
final readonly class LogLevelFromEnvironment implements EnvVarProcessorInterface
{
    private const string DEFAULT_LEVEL = LogLevel::WARNING;

    public function getEnv(string $prefix, string $name, Closure $getEnv): string
    {
        try {
            $value = $getEnv($name);
        } catch (EnvNotFoundException) {
            return self::DEFAULT_LEVEL;
        }

        $accepted = $this->acceptedLevels();
        $level = array_find($accepted, static fn (string $level): bool => $level === $value);

        if (null === $level) {
            // The value is left out, because a credential pasted into the wrong variable would reach the terminal before any redaction runs.
            throw new InvalidArgumentException(sprintf('%s is set to a value that is not a log level: use one of %s.', $name, implode(', ', $accepted)));
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
}
