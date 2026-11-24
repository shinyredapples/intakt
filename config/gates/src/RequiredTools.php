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

    private const string COMPOSER_MINIMUM = '2.11';

    // Every gate reads git, so git is not listed per gate, but a run that selects no gate reads nothing.
    private const array COMMON = ['git'];

    // Because bin/check starts each gate's child process in a session of its own, through `setsid` and `sh`.
    private const array GATE_CHILD = ['sh', 'setsid'];

    // Because a child in a session of its own gets no Ctrl-C from the terminal, so only these can stop its group.
    private const array GATE_CHILD_EXTENSIONS = ['pcntl' => 'pcntl_signal', 'posix' => 'posix_kill'];

    private const array BY_GATE = [
        'audit' => ['composer'],
        'journeys' => ['sh', 'env', 'setsid', 'unshare', 'true'],
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
        return [] === $gates ? [] : $this->missingAmong($this->commandsOf($gates));
    }

    /**
     * @param list<string> $gates
     *
     * @return list<string>
     */
    public function missingToRunEachInItsOwnProcess(array $gates): array
    {
        if ([] === $gates) {
            return [];
        }

        $extensions = array_keys(array_filter(self::GATE_CHILD_EXTENSIONS, static fn (string $function): bool => !function_exists($function)));

        return [
            ...$this->missingAmong([...$this->commandsOf($gates), ...self::GATE_CHILD]),
            ...array_map(static fn (string $extension): string => self::notFound('PHP extension '.$extension), $extensions),
        ];
    }

    /**
     * @param list<string> $gates
     *
     * @return list<string>
     */
    private function commandsOf(array $gates): array
    {
        $commands = [...self::COMMON];

        foreach ($gates as $gate) {
            array_push($commands, ...(self::BY_GATE[$gate] ?? []));
        }

        return $commands;
    }

    /**
     * @param list<string> $commands
     *
     * @return list<string>
     */
    private function missingAmong(array $commands): array
    {
        $entries = explode(PATH_SEPARATOR, $this->searchPath());

        // Because the override may name a command another gate needs too, names are deduplicated after it applies.
        $missing = array_map($this->nameOf(...), $commands)
            |> array_unique(...)
            |> (fn (array $names): array => array_filter($names, fn (string $name): bool => !$this->isAvailable($name, $entries)));

        $tooOld = in_array('composer', $commands, true) && !in_array('composer', $missing, true) ? $this->composerTooOld() : null;

        return [...array_map(self::notFound(...), array_values($missing)), ...(null === $tooOld ? [] : [$tooOld])];
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

    // Because an older Composer ignores config.policy.cooldown silently, it would lock and pass releases inside the period.
    private function composerTooOld(): ?string
    {
        try {
            $query = new Process(['composer', '--version', '--no-ansi', '--no-plugins', '--no-interaction'], $this->root);
            $query->run();
        } catch (ExceptionInterface) {
            return $this->composerRefused('composer version could not be queried');
        }

        $found = 1 === preg_match('/^Composer version (\d+\.\d+\.\d+)/m', $query->getOutput(), $match) ? $match[1] : null;

        if (null !== $found && version_compare($found, self::COMPOSER_MINIMUM, '>=')) {
            return null;
        }

        return $this->composerRefused(null === $found ? 'composer reports no version' : 'composer '.$found.' found');
    }

    private function composerRefused(string $finding): string
    {
        return $finding.', '.self::COMPOSER_MINIMUM.' or later is required for policy.cooldown, see '.self::REQUIREMENTS;
    }

    private function isExecutable(string $path): bool
    {
        return is_file($path) && is_executable($path);
    }

    // Because a gate starts its command in the workspace root, a relative or empty PATH entry resolves there, as the shell does.
    private function directoryOf(string $entry): string
    {
        if ('' === $entry) {
            return $this->root;
        }

        return str_starts_with($entry, '/') ? $entry : $this->root.'/'.$entry;
    }
}
