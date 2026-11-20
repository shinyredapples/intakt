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
        $index = 0;

        foreach ($this->arguments($call) as [$name, $value]) {
            if (null === $name ? $index++ === $position : in_array($name, $names, true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<array{?string, Expr}>
     */
    private function arguments(MethodCall|NullsafeMethodCall|StaticCall $call): array
    {
        $arguments = [];

        foreach ($call->getArgs() as $argument) {
            $spread = $this->spread($argument);

            if (null === $spread) {
                return $arguments;
            }

            array_push($arguments, ...$spread);
        }

        return $arguments;
    }

    // a literal array spreads into its items, because PHP passes them as if written out, and any other unpacked value gives null since it hides every later position
    /**
     * @return ?list<array{?string, Expr}>
     */
    private function spread(Arg $argument): ?array
    {
        if (!$argument->unpack) {
            return [[$argument->name?->toString(), $argument->value]];
        }

        if (!$argument->value instanceof Array_) {
            return null;
        }

        $items = array_map($this->item(...), $argument->value->items);

        return in_array(null, $items, true) ? null : array_values($items);
    }

    /**
     * @return ?array{?string, Expr}
     */
    private function item(ArrayItem $item): ?array
    {
        return match (true) {
            $item->unpack => null,
            !$item->key instanceof Expr, $item->key instanceof Int_ => [null, $item->value],
            $item->key instanceof String_ => [$item->key->value, $item->value],
            default => null,
        };
    }
}
