<?php

declare(strict_types=1);

namespace Intakt\Gates\Architecture;

use Arkitect\Analyzer\ClassDescription;
use Arkitect\Expression\Description;
use Arkitect\Expression\Expression;
use Arkitect\Rules\Violation;
use Arkitect\Rules\ViolationMessage;
use Arkitect\Rules\Violations;

final readonly class InnerLayerStaysIndependent implements Expression
{
    private const array OUTER_LAYERS = ['UseCase', 'Infrastructure'];

    public function __construct(private string $innerLayer)
    {
    }

    public function describe(ClassDescription $theClass, string $because): Description
    {
        return new Description('should not depend on UseCase or Infrastructure of its module', $because);
    }

    public function evaluate(ClassDescription $theClass, Violations $violations, string $because): void
    {
        $own = ModuleAddress::ofClass($theClass->getFQCN());

        if (!$own instanceof ModuleAddress || $own->layer() !== $this->innerLayer) {
            return;
        }

        foreach ($theClass->getDependencies() as $dependency) {
            $name = $dependency->getFQCN()->toString();

            if (!$this->isOuterLayerOf($own, ModuleAddress::ofClass($name))) {
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

    private function isOuterLayerOf(ModuleAddress $own, ?ModuleAddress $used): bool
    {
        return $used instanceof ModuleAddress && $own->isSameModuleAs($used) && in_array($used->layer(), self::OUTER_LAYERS, true);
    }
}
