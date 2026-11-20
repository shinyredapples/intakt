<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

final readonly class SerializerCallArguments
{
    /**
     * @param list<string> $names
     */
    public function valueAt(MethodCall|NullsafeMethodCall|StaticCall $call, int $position, array $names): ?Expr
    {
        $passed = 0;

        foreach ($call->getArgs() as $argument) {
            $values = $this->passedBy($argument);
            $found = $this->formatIn(null === $passed ? array_filter($values ?? [], is_string(...), \ARRAY_FILTER_USE_KEY) : $values ?? [], $position - (int) $passed, $names);

            if (null !== $found) {
                return $found;
            }

            // an unpacked value the rule cannot read hides every later position, while a later named argument still names its parameter
            $passed = null === $values || null === $passed ? null : $passed + count(array_filter(array_keys($values), is_int(...)));
        }

        return null;
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

    // the values one argument passes, keyed as PHP passes them: an integer key is the next positional argument, a string key names its parameter; null when an unpacked value is no literal array the rule can read
    /**
     * @return ?array<int|string, Expr>
     */
    private function passedBy(Arg $argument): ?array
    {
        return match (true) {
            !$argument->unpack => null === $argument->name ? [$argument->value] : [$argument->name->toString() => $argument->value],
            $argument->value instanceof Array_ => $this->entriesOf($argument->value),
            default => null,
        };
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
        if ($item->unpack) {
            $nested = $item->value instanceof Array_ ? $this->entriesOf($item->value) : null;

            return null === $nested ? null : [...$entries, ...$nested];
        }

        if ($item->key instanceof Expr && !$item->key instanceof Int_ && !$item->key instanceof String_) {
            return null;
        }

        if (null === $item->key) {
            $entries[] = $item->value;
        } else {
            $entries[$item->key->value] = $item->value;
        }

        return $entries;
    }
}
