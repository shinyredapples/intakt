<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

// PHP looks in the calling namespace first for an unqualified preg_match(), so a function defined there at run time (not in a file the analyzers see) makes CommitId's check throw for the rest of the process; only #[RunInSeparateProcess] tests may define it.
final class CommitIdCheckThatThrows
{
    // PHP caches the function a call resolves to, so this must run before the process builds its first CommitId.
    public static function defineFor(string $hash): void
    {
        $code = "<?php\nnamespace Intakt\\Plugin\\Forge\\Contract;\n"
            ."use InvalidArgumentException;\n"
            ."use function preg_match as matchGlobally;\n"
            .'function preg_match(string $pattern, string $subject, &$matches = null, int $flags = 0, int $offset = 0): int|false {'
            .' if ('.var_export($hash, true).' === $subject) { throw new InvalidArgumentException(\'unexpected\'); }'
            .' return matchGlobally($pattern, $subject, $matches, $flags, $offset); }'."\n";

        $file = tempnam(sys_get_temp_dir(), 'commit-id-check-');
        file_put_contents($file, $code);
        require $file;
        unlink($file);
    }
}
