<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a colon in a list of statements ends a label or a case, while elsewhere it starts a value, and `of` is a keyword only in a for head.
enum JavaScriptFrame
{
    case Statements;
    case Expression;
    case ForHead;
}
