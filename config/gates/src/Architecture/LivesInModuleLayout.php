<?php

declare(strict_types=1);

namespace Intakt\Gates\Architecture;

use Arkitect\Analyzer\ClassDescription;
use Arkitect\Expression\Description;
use Arkitect\Expression\Expression;
use Arkitect\Rules\Violation;
use Arkitect\Rules\ViolationMessage;
use Arkitect\Rules\Violations;

final class LivesInModuleLayout implements Expression
{
    private const string SHARED_LAYER = '/^(Contract|Domain|Infrastructure)\\\\\w+(\\\\\w+)*$/';
    private const string USE_CASE = '/^UseCase\\\\\w+\\\\\w+(\\\\\w+)*$/';

    public function describe(ClassDescription $theClass, string $because): Description
    {
        return new Description('should live in src/<Area>/<Module>/ in Contract, Domain, Infrastructure or UseCase/<Name>/, in the folder its namespace names', $because);
    }

    public function evaluate(ClassDescription $theClass, Violations $violations, string $because): void
    {
        $address = ModuleAddress::ofClass($theClass->getFQCN());
        $problem = $address instanceof ModuleAddress ? $this->problemWith($address, $theClass->getFilePath()) : 'is not in an Intakt\\(Core|Plugin)\\<Module> namespace';

        if (null === $problem) {
            return;
        }

        $violations->add(Violation::create(
            $theClass->getFQCN(),
            ViolationMessage::withDescription($this->describe($theClass, $because), $problem),
            $theClass->getFilePath(),
        ));
    }

    private function problemWith(ModuleAddress $address, string $filePath): ?string
    {
        $expected = $this->expectedPath($address);

        if (null === $expected) {
            return 'is not in Contract, Domain, Infrastructure or UseCase/<Name>';
        }

        $path = '/'.ltrim(str_replace('\\', '/', $filePath), '/');

        return str_ends_with($path, '/'.$expected) ? null : 'is not in src/'.$expected;
    }

    private function expectedPath(ModuleAddress $address): ?string
    {
        $inside = $address->inside;

        return match (true) {
            1 === preg_match(self::SHARED_LAYER, $inside), 1 === preg_match(self::USE_CASE, $inside) => $address->directory().str_replace('\\', '/', $inside).'.php',
            default => null,
        };
    }
}
