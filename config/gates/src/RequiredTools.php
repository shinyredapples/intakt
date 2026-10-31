<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

final readonly class RequiredTools
{
    public const string SCANNER_ENVIRONMENT = 'INTAKT_GITLEAKS';

    public const string REQUIREMENTS = 'docs/architecture/requirements.md';

    private const string SCANNER = 'gitleaks';

    // Every gate reads git, so git is not listed per gate, but a run that selects no gate reads nothing.
    private const array COMMON = ['git'];

    private const array BY_GATE = [
        'audit' => ['composer'],
        'tests' => ['sh', 'unshare', 'setsid', 'true', 'bash', 'jq'],
        'secrets' => [self::SCANNER, 'env'],
        'secrets-history' => [self::SCANNER, 'env'],
    ];

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
        if ([] === $gates) {
            return [];
        }

        $commands = [...self::COMMON];

        foreach ($gates as $gate) {
            array_push($commands, ...(self::BY_GATE[$gate] ?? []));
        }

        $entries = explode(PATH_SEPARATOR, $this->searchPath());

        // Because the override may name a command another gate needs too, names are deduplicated after it applies.
        $missing = array_map($this->nameOf(...), $commands)
            |> array_unique(...)
            |> (fn (array $names): array => array_filter($names, fn (string $name): bool => !$this->isAvailable($name, $entries)));

        return array_map(self::notFound(...), array_values($missing));
    }

    private function searchPath(): string
    {
        $path = getenv('PATH');

        return is_string($path) ? $path : $this->defaultPath();
    }

    // Because Symfony Process starts every gate command as `exec` in /bin/sh, which searches its own default path when PATH is unset.
    private function defaultPath(): string
    {
        try {
            $query = new Process(['/bin/sh', '-c', 'printf %s "$PATH"'], null, ['PATH' => false]);
            $query->run();
        } catch (ExceptionInterface) {
            return '';
        }

        return $query->isSuccessful() ? trim($query->getOutput()) : '';
    }

    private function nameOf(string $command): string
    {
        return self::SCANNER === $command ? self::scanner() : $command;
    }

    /**
     * @param list<string> $entries
     */
    private function isAvailable(string $name, array $entries): bool
    {
        if (str_contains($name, '/')) {
            return $this->isExecutable(str_starts_with($name, '/') ? $name : $this->root.'/'.$name);
        }

        return array_any($entries, fn (string $entry): bool => $this->isExecutable($this->directoryOf($entry).'/'.$name));
    }

    // Because a gate starts its command in the workspace root, a relative or empty PATH entry resolves there, as the shell does.
    private function directoryOf(string $entry): string
    {
        if ('' === $entry) {
            return $this->root;
        }

        return str_starts_with($entry, '/') ? $entry : $this->root.'/'.$entry;
    }

    private function isExecutable(string $path): bool
    {
        return is_file($path) && is_executable($path);
    }
}
