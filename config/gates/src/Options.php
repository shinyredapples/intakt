<?php

declare(strict_types=1);

namespace Intakt\Gates;

use InvalidArgumentException;

final readonly class Options
{
    private const string REUSE = '--reuse';

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

    public bool $reuse;

    /**
     * @param array{base: ?string, only: ?string, skip: ?string, root: ?string, 'test-merge': ?string} $values
     * @param list<string>                                                                             $flags
     */
    private function __construct(public Mode $mode, array $values, array $flags)
    {
        $this->reuse = in_array(self::REUSE, $flags, true);
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
        $flags = [];

        foreach ($arguments as $argument) {
            $mode = self::modeNamedBy($argument);

            if ($mode instanceof Mode) {
                $modes[] = $mode;
                continue;
            }

            if (self::REUSE === $argument) {
                $flags[] = $argument;
                continue;
            }

            $values = self::withValue($values, $argument);
        }

        return self::withMode($modes, $values, $flags);
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

        throw new InvalidArgumentException('unknown argument '.$argument.', usage: bin/check [--fast|--journeys] [--reuse] [--base=<ref>] [--only=<gate,...>] [--skip=<gate,...>] [--test-merge=<merge>:<head>], or bin/check --static-gates');
    }

    /**
     * @param list<Mode>                                                                               $modes
     * @param array{base: ?string, only: ?string, skip: ?string, root: ?string, 'test-merge': ?string} $values
     * @param list<string>                                                                             $flags
     */
    private static function withMode(array $modes, array $values, array $flags): self
    {
        if (!in_array(Mode::Journeys, $modes, true)) {
            return new self(array_last($modes) ?? Mode::Full, $values, $flags);
        }

        $options = new self(Mode::Journeys, $values, $flags);
        $others = array_filter($modes, static fn (Mode $mode): bool => Mode::Journeys !== $mode);

        // Because the journeys boot whole installs whose outcome the tree alone does not decide, their pass is never reused.
        if ([] !== $others || [] !== $options->only || [] !== $options->skip || null !== $values['base'] || null !== $values['test-merge'] || $options->reuse) {
            throw new InvalidArgumentException('--journeys runs alone, without --fast, --reuse, --only, --skip, --base or --test-merge');
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
