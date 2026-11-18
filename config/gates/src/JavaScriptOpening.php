<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because some words decide what the next bracket opens (a condition, a function's parameters or body, a class body), that expectation is carried until the bracket comes.
enum JavaScriptOpening
{
    case Nothing;
    case Condition;
    case ForHead;
    case FunctionHead;
    case FunctionBody;
    case ClassBody;
    case ArrowBody;
}
