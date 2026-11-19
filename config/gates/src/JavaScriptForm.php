<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a function or class written where an expression starts is a value a slash divides, while a declaration ends a statement.
enum JavaScriptForm: string
{
    case Declaration = 'declaration';
    case Expression = 'expression';
}
