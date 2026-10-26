<?php

declare(strict_types=1);

namespace Intakt\Gates\Architecture;

use Arkitect\Analyzer\ClassDescription;
use Arkitect\Expression\Description;
use Arkitect\Expression\Expression;
use Arkitect\Rules\Violation;
use Arkitect\Rules\ViolationMessage;
use Arkitect\Rules\Violations;

final class OtherModulesOnlyThroughContract implements Expression
{
    public function describe(ClassDescription $theClass, string $because): Description
    {
        return new Description('should use other modules only through their Contract namespace', $because);
    }

    public function evaluate(ClassDescription $theClass, Violations $violations, string $because): void
    {
        $own = ModuleAddress::ofClass($theClass->getFQCN());

        foreach ($theClass->getDependencies() as $dependency) {
            $name = $dependency->getFQCN()->toString();

            if (!$this->reachesIntoAnotherModule($own, ModuleAddress::ofClass($name))) {
                continue;
            }

            $violations->add(Violation::createWithErrorLine(
                $theClass->getFQCN(),
                ViolationMessage::withDescription($this->describe($theClass, $because), 'depends on '.$name),
                $dependency->getLine(),
                $theClass->getFilePath(),
            ));
        }
    }

    private function reachesIntoAnotherModule(?ModuleAddress $own, ?ModuleAddress $used): bool
    {
        if (!$used instanceof ModuleAddress || $used->isInContract()) {
            return false;
        }

        return !$own instanceof ModuleAddress || !$own->isSameModuleAs($used);
    }
}
