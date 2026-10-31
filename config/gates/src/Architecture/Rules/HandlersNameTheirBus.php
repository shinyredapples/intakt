<?php

declare(strict_types=1);

namespace Intakt\Gates\Architecture\Rules;

use Arkitect\Rules\DSL\ArchRule;
use Arkitect\Rules\Rule;
use Intakt\Gates\Architecture\ArchitectureRule;
use Intakt\Gates\Architecture\EveryClass;
use Intakt\Gates\Architecture\MessageHandlerOnlyThroughContractAttributes;

final readonly class HandlersNameTheirBus implements ArchitectureRule
{
    public function rule(): ArchRule
    {
        return Rule::allClasses()->that(new EveryClass())->should(new MessageHandlerOnlyThroughContractAttributes())->because('handlers name their bus through the Platform attributes');
    }
}
