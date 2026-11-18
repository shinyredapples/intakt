<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because what follows a closing bracket depends on its opening one and the code around it, each open bracket keeps the enclosing state and what comes after it.
final readonly class JavaScriptBracket
{
    public function __construct(
        public JavaScriptFrame $enclosing,
        public int $enclosingTernaries,
        public JavaScriptPosition $after,
        public JavaScriptOpening $opensAfter,
        public bool $isExpressionAfter,
    ) {
    }
}
