<?php

declare(strict_types=1);

namespace Intakt\Gates\Architecture;

use Arkitect\Analyzer\ClassDescription;
use Arkitect\Expression\Description;
use Arkitect\Expression\Expression;
use Arkitect\Rules\Violation;
use Arkitect\Rules\ViolationMessage;
use Arkitect\Rules\Violations;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class MessageHandlerOnlyThroughContractAttributes implements Expression
{
    private const string PLATFORM_CONTRACT = 'Intakt\Core\Platform\Contract\\';

    public function describe(ClassDescription $theClass, string $because): Description
    {
        return new Description('should declare handlers with AsCommandHandler or AsQueryHandler, not AsMessageHandler', $because);
    }

    public function evaluate(ClassDescription $theClass, Violations $violations, string $because): void
    {
        if (str_starts_with($theClass->getFQCN(), self::PLATFORM_CONTRACT)) {
            return;
        }

        foreach ($theClass->getDependencies() as $dependency) {
            if (AsMessageHandler::class !== $dependency->getFQCN()->toString()) {
                continue;
            }

            $violations->add(Violation::createWithErrorLine(
                $theClass->getFQCN(),
                ViolationMessage::withDescription($this->describe($theClass, $because), 'uses '.AsMessageHandler::class.'; use AsCommandHandler or AsQueryHandler from Intakt\Core\Platform\Contract'),
                $dependency->getLine(),
                $theClass->getFilePath(),
            ));
        }
    }
}
