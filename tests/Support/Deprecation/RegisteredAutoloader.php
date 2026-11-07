<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation;

use Closure;
use LogicException;
use ReflectionFunction;
use ReflectionMethod;

final readonly class RegisteredAutoloader
{
    private function __construct(
        private ?string $class,
        private string $function,
        private ?string $file,
    ) {
    }

    public static function of(callable $autoloader): self
    {
        $reflection = match (true) {
            is_array($autoloader) => new ReflectionMethod($autoloader[0], $autoloader[1]),
            is_string($autoloader) && str_contains($autoloader, '::') => ReflectionMethod::createFromMethodName($autoloader),
            $autoloader instanceof Closure, is_string($autoloader) => new ReflectionFunction($autoloader),
            is_object($autoloader) => new ReflectionMethod($autoloader, '__invoke'),
            default => throw new LogicException('An autoloader of an unknown callable shape is registered.'),
        };
        $file = $reflection->getFileName();

        return new self(
            $reflection instanceof ReflectionMethod ? $reflection->getDeclaringClass()->getName() : null,
            $reflection->getName(),
            false === $file ? null : $file,
        );
    }

    /**
     * @param array<string, mixed> $frame
     */
    public function isCalledIn(array $frame): bool
    {
        return ($frame['function'] ?? null) === $this->function && (null === $this->class || ($frame['class'] ?? null) === $this->class);
    }

    // Because the only frames between a deprecation the loading itself raises and the autoloader call run in the autoloader's own file (the include of the class file, Composer's include closure) or, without a file, in its class.
    /**
     * @param array<string, mixed> $frame
     */
    public function runs(array $frame): bool
    {
        $file = $frame['file'] ?? null;

        if (null === $file) {
            return null !== $this->class && ($frame['class'] ?? null) === $this->class;
        }

        return null !== $this->file && $file === $this->file;
    }
}
