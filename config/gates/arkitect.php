<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Rules\DSL\ArchRule;
use Intakt\Gates\Architecture\Rules\ContractStaysIndependent;
use Intakt\Gates\Architecture\Rules\DomainStaysIndependent;
use Intakt\Gates\Architecture\Rules\HandlersNameTheirBus;
use Intakt\Gates\Architecture\Rules\ModuleKeepsItsLayout;
use Intakt\Gates\Architecture\Rules\ModulesMeetOnlyThroughContract;

$rules = [
    ModulesMeetOnlyThroughContract::class,
    ModuleKeepsItsLayout::class,
    DomainStaysIndependent::class,
    ContractStaysIndependent::class,
];

return static function (Config $config) use ($rules): void {
    $root = (string) getcwd();
    if (!is_dir($root.'/src')) {
        return;
    }

    $config->add(
        ClassSet::fromDir($root.'/src')->excludePath('Kernel.php'),
        ...array_map(static fn (string $rule): ArchRule => new $rule()->rule(), $rules),
    );
    // The kernel sits outside the module layout but must not declare a handler either.
    $config->add(
        ClassSet::fromDir($root.'/src'),
        new HandlersNameTheirBus()->rule(),
    );
};
