<?php

declare(strict_types=1);

namespace Intakt\Gates\Architecture;

use Arkitect\Analyzer\ClassDescription;
use Arkitect\Expression\Description;
use Arkitect\Expression\Expression;
use Arkitect\Rules\Violations;

final class EveryClass implements Expression
{
    public function describe(ClassDescription $theClass, string $because): Description
    {
        return new Description('is any class', $because);
    }

    public function evaluate(ClassDescription $theClass, Violations $violations, string $because): void
    {
    }
}
