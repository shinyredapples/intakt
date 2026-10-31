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
        if (str_contains($name, '/')) {
            return $this->isExecutable(str_starts_with($name, '/') ? $name : $this->root.'/'.$name);
        }

        $found = new ExecutableFinder()->find($name, null, $this->relativePathEntriesUnderTheRoot());

        if (null === $found) {
            return false;
        }

        // Because the finder resolves a relative PATH entry from the caller's directory, such a find counts only when the root holds it too.
        return str_starts_with($found, '/') || $this->isExecutable($this->root.'/'.$found);
    }

    /**
     * @return list<string>
     */
    private function relativePathEntriesUnderTheRoot(): array
    {
        $root = str_starts_with($this->root, '/') ? $this->root : (getcwd() ?: '.').'/'.$this->root;
        $entries = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        $relative = array_filter($entries, static fn (string $entry): bool => !str_starts_with($entry, '/'));

        return array_values(array_map(static fn (string $entry): string => rtrim($root.'/'.$entry, '/'), $relative));
    }

    private function isExecutable(string $path): bool
    {
        return is_file($path) && is_executable($path);
    }
}
