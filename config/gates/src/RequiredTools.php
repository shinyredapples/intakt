<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\Process\ExecutableFinder;

final readonly class RequiredTools
{
    public const string SCANNER_ENVIRONMENT = 'INTAKT_GITLEAKS';

    public const string REQUIREMENTS = 'docs/architecture/requirements.md';

    private const string SCANNER = 'gitleaks';

    // Every gate reads git, so git is not listed per gate.
    private const array COMMON = ['git'];

    private const array BY_GATE = [
        'audit' => ['composer'],
        'tests' => ['sh', 'unshare', 'setsid', 'true', 'bash', 'jq'],
        'secrets' => [self::SCANNER, 'env'],
        'secrets-history' => [self::SCANNER, 'env'],
    ];

    public static function scanner(): string
    {
        $named = getenv(self::SCANNER_ENVIRONMENT);

        return is_string($named) && '' !== $named ? $named : self::SCANNER;
    }

    public static function notFound(string $command): string
    {
        return $command.' not found, see '.self::REQUIREMENTS;
    }

    /**
     * @param list<string> $gates
     *
     * @return list<string>
     */
    public function missingFor(array $gates): array
    {
        $commands = [...self::COMMON];

        foreach ($gates as $gate) {
            array_push($commands, ...(self::BY_GATE[$gate] ?? []));
        }

        $missing = array_filter(array_unique($commands), fn (string $command): bool => !$this->isAvailable($command));

        return array_map(fn (string $command): string => self::notFound($this->nameOf($command)), array_values($missing));
    }

    private function nameOf(string $command): string
    {
        return self::SCANNER === $command ? self::scanner() : $command;
    }

    private function isAvailable(string $command): bool
    {
        $name = $this->nameOf($command);

        if (str_contains($name, '/')) {
            return is_file($name) && is_executable($name);
        }

        return null !== new ExecutableFinder()->find($name);
    }
}
