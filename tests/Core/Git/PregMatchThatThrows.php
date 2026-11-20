<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git;

// PHP looks in the calling namespace first for an unqualified preg_match(), so a function defined there at run time (not in a file the analyzers see) makes the module's calls throw for the rest of the process; only #[RunInSeparateProcess] tests may define it.
final class PregMatchThatThrows
{
    // Must name the namespace of the preg_match() call the tests reach first (Mirrors::pathOf()), or the override is never called.
    private const array NAMESPACES = ['Intakt\Core\Git\Domain'];

    public static function define(): void
    {
        $code = '<?php';
        foreach (self::NAMESPACES as $namespace) {
            $code .= "\nnamespace {$namespace};\n"
                .'function preg_match(string $pattern, string $subject, &$matches = null, int $flags = 0, int $offset = 0): int|false {'
                .' throw new \InvalidArgumentException(\'unexpected\'); }'."\n";
        }

        $file = tempnam(sys_get_temp_dir(), 'preg-match-');
        file_put_contents($file, $code);
        require $file;
        unlink($file);
    }
}
