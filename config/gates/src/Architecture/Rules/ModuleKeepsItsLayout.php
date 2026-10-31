<?php

declare(strict_types=1);

namespace Intakt\Gates\Architecture\Rules;

use Arkitect\Rules\DSL\ArchRule;
use Arkitect\Rules\Rule;
use Intakt\Gates\Architecture\ArchitectureRule;
use Intakt\Gates\Architecture\EveryClass;
use Intakt\Gates\Architecture\LivesInModuleLayout;

final readonly class ModuleKeepsItsLayout implements ArchitectureRule
{
    public function rule(): ArchRule
    {
        return Rule::allClasses()->that(new EveryClass())->should(new LivesInModuleLayout())->because('a module keeps its public surface in Contract and its use cases in UseCase');
    }
}
