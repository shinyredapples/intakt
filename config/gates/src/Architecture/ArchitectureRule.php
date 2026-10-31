<?php

declare(strict_types=1);

namespace Intakt\Gates\Architecture;

use Arkitect\Rules\DSL\ArchRule;

interface ArchitectureRule
{
    public function rule(): ArchRule;
}
