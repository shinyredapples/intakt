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

    // Because the gates run their commands in the workspace root, a relative path resolves there.
    public function __construct(
        private string $root,
    ) {
    }

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

        // Because the override may name a command another gate needs too, names are deduplicated after it applies.
        $missing = array_map($this->nameOf(...), $commands)
            |> array_unique(...)
            |> (fn (array $names): array => array_filter($names, fn (string $name): bool => !$this->isAvailable($name)));

        return array_map(self::notFound(...), array_values($missing));
    }

    private function nameOf(string $command): string
    {
        return self::SCANNER === $command ? self::scanner() : $command;
    }

    private function isAvailable(string $name): bool
    {
        if (!str_contains($name, '/')) {
            return null !== new ExecutableFinder()->find($name);
        }

        $path = str_starts_with($name, '/') ? $name : $this->root.'/'.$name;

        return is_file($path) && is_executable($path);
    }
}
