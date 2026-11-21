<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PHPStan\Reflection\ClassReflection;

final readonly class AncestorDeclarations
{
    public const string BUILT_IN = '';

    /**
     * @return list<string>
     */
    public function filesDeclaring(ClassReflection $class, string $method): array
    {
        $files = [];

        foreach ([...$class->getInterfaces(), ...$class->getParents()] as $ancestor) {
            $native = $ancestor->getNativeReflection();

            // a child's method of the same name does not override a private one, so its name is the author's choice
            if ($native->hasMethod($method) && !$native->getMethod($method)->isPrivate()) {
                $file = $native->getMethod($method)->getDeclaringClass()->getFileName();
                $files[] = false === $file ? self::BUILT_IN : $file;
            }
        }

        return $files;
    }
}
