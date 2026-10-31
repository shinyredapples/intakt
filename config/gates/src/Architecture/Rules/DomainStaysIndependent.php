<?php

declare(strict_types=1);

namespace Intakt\Gates\Architecture\Rules;

use Arkitect\Rules\DSL\ArchRule;
use Arkitect\Rules\Rule;
use Intakt\Gates\Architecture\ArchitectureRule;
use Intakt\Gates\Architecture\EveryClass;
use Intakt\Gates\Architecture\InnerLayerStaysIndependent;

final readonly class DomainStaysIndependent implements ArchitectureRule
{
    public function rule(): ArchRule
    {
        return Rule::allClasses()->that(new EveryClass())->should(new InnerLayerStaysIndependent('Domain'))->because('the domain does not know its callers');
    }
}
