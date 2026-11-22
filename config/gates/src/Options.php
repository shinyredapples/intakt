<?php

declare(strict_types=1);

namespace Intakt\Gates;

use InvalidArgumentException;

final readonly class Options
{
    public ?string $base;

    /**
     * @var list<string>
     */
    public array $only;

    /**
     * @var list<string>
     */
    public array $skip;

    public ?string $root;

    public ?string $testMerge;

    /**
     * @param array{base: ?string, only: ?string, skip: ?string, root: ?string, 'test-merge': ?string} $values
     */
    private function __construct(public Mode $mode, array $values)
    {
        $this->base = $values['base'];
        $this->only = $this->names($values['only']);
        $this->skip = $this->names($values['skip']);
        $this->root = $values['root'];
        $this->testMerge = $values['test-merge'];
    }

    /**
     * @param list<string> $arguments
     */
    public static function fromArguments(array $arguments): self
    {
        $modes = [];
        $values = ['base' => null, 'only' => null, 'skip' => null, 'root' => null, 'test-merge' => null];

        foreach ($arguments as $argument) {
            $mode = self::modeNamedBy($argument);

            if ($mode instanceof Mode) {
                $modes[] = $mode;
                continue;
            }

            $values = self::withValue($values, $argument);
        }

        return self::withMode($modes, $values);
    }

    private static function modeNamedBy(string $argument): ?Mode
    {
        return match ($argument) {
            '--fast' => Mode::ChangedFilesOnly,
            '--journeys' => Mode::Journeys,
            default => null,
        };
    }

    /**
     * @param array{base: ?string, only: ?string, skip: ?string, root: ?string, 'test-merge': ?string} $values
     *
     * @return array{base: ?string, only: ?string, skip: ?string, root: ?string, 'test-merge': ?string}
     */
    private static function withValue(array $values, string $argument): array
    {
        foreach (array_keys($values) as $name) {
            if (str_starts_with($argument, '--'.$name.'=')) {
                $values[$name] = substr($argument, strlen($name) + 3);

                return $values;
            }
        }

        throw new InvalidArgumentException('unknown argument '.$argument.', usage: bin/check [--fast|--journeys] [--base=<ref>] [--only=<gate,...>] [--skip=<gate,...>] [--test-merge=<merge>:<head>], or bin/check --static-gates');
    }

    /**
     * @param list<Mode>                                                                               $modes
     * @param array{base: ?string, only: ?string, skip: ?string, root: ?string, 'test-merge': ?string} $values
     */
    private static function withMode(array $modes, array $values): self
    {
        if (!in_array(Mode::Journeys, $modes, true)) {
            return new self(array_last($modes) ?? Mode::Full, $values);
        }

        $options = new self(Mode::Journeys, $values);
        $others = array_filter($modes, static fn (Mode $mode): bool => Mode::Journeys !== $mode);

        if ([] !== $others || [] !== $options->only || [] !== $options->skip || null !== $values['base'] || null !== $values['test-merge']) {
            throw new InvalidArgumentException('--journeys runs alone, without --fast, --only, --skip, --base or --test-merge');
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function names(?string $list): array
    {
        return null === $list ? [] : explode(',', $list);
    }
}
