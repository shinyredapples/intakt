<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a slash where a statement or an expression starts opens a regular expression, a slash after an operand divides, and a brace opens a block only where a statement starts.
enum JavaScriptPosition
{
    case StatementStart;
    case ExpressionStart;
    case AfterOperand;
}
