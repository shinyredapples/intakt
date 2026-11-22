<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Closure;

// PHP looks in the calling namespace first for an unqualified is_dir(), so a function defined there at run time runs the other remover right after the forge saw the snapshot present, the order a judged round's removal can take against the command; only #[RunInSeparateProcess] tests may define it, because it stays for the rest of the process.
final class IsDirThatLetsAnotherRemoverIn
{
    private const string NAMESPACE = 'Intakt\Plugin\LocalGitForge\Infrastructure';

    private static ?Closure $otherRemover = null;

    /**
     * @param Closure(string): void $otherRemover
     */
    public static function define(Closure $otherRemover): void
    {
        self::$otherRemover = $otherRemover;
        $code = '<?php'."\nnamespace ".self::NAMESPACE.";\n"
            .'function is_dir(string $filename): bool { return \\'.self::class.'::seen($filename); }'."\n";

        $file = tempnam(sys_get_temp_dir(), 'is-dir-');
        file_put_contents($file, $code);
        require $file;
        unlink($file);
    }

    public static function seen(string $filename): bool
    {
        $present = is_dir($filename);
        $otherRemover = self::$otherRemover;

        if ($present && $otherRemover instanceof Closure) {
            self::$otherRemover = null;
            $otherRemover($filename);
        }

        return $present;
    }
}
