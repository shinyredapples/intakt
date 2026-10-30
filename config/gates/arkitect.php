<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Rules\Rule;
use Intakt\Gates\Architecture\EveryClass;
use Intakt\Gates\Architecture\InnerLayerStaysIndependent;
use Intakt\Gates\Architecture\LivesInModuleLayout;
use Intakt\Gates\Architecture\MessageHandlerOnlyThroughContractAttributes;
use Intakt\Gates\Architecture\OtherModulesOnlyThroughContract;

return static function (Config $config): void {
    $root = (string) getcwd();
    if (!is_dir($root.'/src')) {
        return;
    }

    $config->add(
        ClassSet::fromDir($root.'/src')->excludePath('Kernel.php'),
        Rule::allClasses()->that(new EveryClass())->should(new OtherModulesOnlyThroughContract())->because('modules meet only through their Contract namespace'),
        Rule::allClasses()->that(new EveryClass())->should(new LivesInModuleLayout())->because('a module keeps its public surface in Contract and its use cases in UseCase'),
        Rule::allClasses()->that(new EveryClass())->should(new InnerLayerStaysIndependent('Domain'))->because('the domain does not know its callers'),
        Rule::allClasses()->that(new EveryClass())->should(new InnerLayerStaysIndependent('Contract'))->because('the contract does not know its callers'),
        Rule::allClasses()->that(new EveryClass())->should(new MessageHandlerOnlyThroughContractAttributes())->because('handlers name their bus through the Platform attributes'),
    );
};
