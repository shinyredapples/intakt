<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git;

use InvalidArgumentException;

// PHP looks in the calling namespace first for an unqualified preg_match(), so a function defined there at run time (not in a file the analyzers see) makes the module's calls throw while armed.
final class PregMatchThatThrows
{
    private const array NAMESPACES = ['Intakt\Core\Git\Domain', 'Intakt\Core\Git\UseCase\ListProtectedChanges'];

    private static bool $armed = false;

    public static function arm(): void
    {
        self::define();
        self::$armed = true;
    }

    public static function disarm(): void
    {
        self::$armed = false;
    }

    public static function failWhenArmed(): void
    {
        if (self::$armed) {
            throw new InvalidArgumentException('unexpected');
        }
    }

    private static function define(): void
    {
        $code = '<?php';
        foreach (self::NAMESPACES as $namespace) {
            if (function_exists($namespace.'\preg_match')) {
                continue;
            }

            $code .= "\nnamespace {$namespace};\n"
                .'function preg_match(string $pattern, string $subject, &$matches = null, int $flags = 0, int $offset = 0) {'
                .' \\'.self::class.'::failWhenArmed(); return \preg_match($pattern, $subject, $matches, $flags, $offset); }'."\n";
        }

        $file = tempnam(sys_get_temp_dir(), 'preg-match-');
        file_put_contents($file, $code);
        require $file;
        unlink($file);
    }
}
