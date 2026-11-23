<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

final readonly class SerializerCallArguments
{
    /**
     * @param list<string> $names
     */
    public function valueAt(MethodCall|StaticCall $call, int $position, array $names): ?Expr
    {
        $passed = 0;

        foreach ($call->getArgs() as $argument) {
            $values = $this->passedBy($argument);
            $found = $this->formatIn(null === $passed ? array_filter($values ?? [], is_string(...), ARRAY_FILTER_USE_KEY) : $values ?? [], $position - (int) $passed, $names);

            if ($found instanceof Expr) {
                return $found;
            }

            // an unpacked value the rule cannot read hides every later position, while a later named argument still names its parameter
            $passed = null === $values || null === $passed ? null : $passed + count(array_filter(array_keys($values), is_int(...)));
        }

        return null;
    }

    // keyed as PHP passes the values, because an integer key is the next positional argument and a string key names its parameter; null because an unpacked value that is no readable literal array hides what it passes
    /**
     * @return ?array<int|string, Expr>
     */
    private function passedBy(Arg $argument): ?array
    {
        return match (true) {
            !$argument->unpack => $argument->name instanceof Identifier ? [$argument->name->toString() => $argument->value] : [$argument->value],
            $argument->value instanceof Array_ => $this->entriesOf($argument->value),
            default => null,
        };
    }

    /**
     * @param array<int|string, Expr> $values
     * @param list<string>            $names
     */
    private function formatIn(array $values, int $position, array $names): ?Expr
    {
        $index = 0;

        return array_find(
            $values,
            static function (Expr $value, int|string $key) use (&$index, $position, $names): bool {
                return is_string($key) ? in_array($key, $names, true) : $index++ === $position;
            },
        );
    }

    // built as a PHP array on purpose, because PHP itself then applies its key rules: a numeric string key such as '1' is the integer key, a repeated key overwrites the earlier entry in place, and a nested spread renumbers integer keys
    /**
     * @return ?array<int|string, Expr>
     */
    private function entriesOf(Array_ $array): ?array
    {
        $entries = [];

        foreach ($array->items as $item) {
            $added = $this->withItem($entries, $item);

            if (null === $added) {
                return null;
            }

            $entries = $added;
        }

        return $entries;
    }

    /**
     * @param array<int|string, Expr> $entries
     *
     * @return ?array<int|string, Expr>
     */
    private function withItem(array $entries, ArrayItem $item): ?array
    {
        return match (true) {
            $item->unpack => $this->withNested($entries, $item->value),
            !$item->key instanceof Expr => $this->withEntry($entries, null, $item->value),
            $item->key instanceof Int_, $item->key instanceof String_ => $this->withEntry($entries, $item->key->value, $item->value),
            default => null,
        };
    }

    /**
     * @param array<int|string, Expr> $entries
     *
     * @return ?array<int|string, Expr>
     */
    private function withNested(array $entries, Expr $spread): ?array
    {
        $nested = $spread instanceof Array_ ? $this->entriesOf($spread) : null;

        foreach ($nested ?? [] as $key => $value) {
            $entries = null === $entries ? null : $this->withEntry($entries, is_int($key) ? null : $key, $value);
        }

        return null === $nested ? null : $entries;
    }

    // written through PHP's own array assignment, because that keeps the next free index and the place of an overwritten key as PHP does; null after the maximum integer key, because PHP refuses that append and a gate must not crash on code that parses
    /**
     * @param array<int|string, Expr> $entries
     *
     * @return ?array<int|string, Expr>
     */
    private function withEntry(array $entries, int|string|null $key, Expr $value): ?array
    {
        if (null === $key && array_key_exists(PHP_INT_MAX, $entries)) {
            return null;
        }

        if (null === $key) {
            $entries[] = $value;

            return $entries;
        }

        $entries[$key] = $value;

        return $entries;
    }
}
