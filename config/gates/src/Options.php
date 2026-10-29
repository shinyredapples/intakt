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
        $mode = Mode::Full;
        $values = ['base' => null, 'only' => null, 'skip' => null, 'root' => null, 'test-merge' => null];

        foreach ($arguments as $argument) {
            if ('--fast' === $argument) {
                $mode = Mode::ChangedFilesOnly;
                continue;
            }

            $values = self::withValue($values, $argument);
        }

        return new self($mode, $values);
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

        throw new InvalidArgumentException('unknown argument '.$argument.', usage: bin/check [--fast] [--base=<ref>] [--only=<gate,...>] [--skip=<gate,...>] [--test-merge=<merge>:<head>]');
    }

    /**
     * @return list<string>
     */
    private function names(?string $list): array
    {
        return null === $list ? [] : explode(',', $list);
    }
}
