<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\KeptSnapshots;
use Psr\Log\NullLogger;

// PHP looks in the calling namespace first for an unqualified is_dir(), so a function defined there at run time lets the judged round's removal take a kept snapshot right after the forge saw it present, the order that removal can take against the command; it holds no state, because the snapshot it removes is gone at the next look, and only #[RunInSeparateProcess] tests may define it, because it stays for the rest of the process.
final readonly class IsDirThatLetsAnotherRemoverIn
{
    private const string NAMESPACE = 'Intakt\Plugin\LocalGitForge\Infrastructure';

    public static function define(): void
    {
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

        if ($present && str_starts_with(basename($filename), LocalGitForge::KEPT_SNAPSHOT_PREFIX)) {
            new KeptSnapshots(new NullLogger())->remove('judged-round', $filename);
        }

        return $present;
    }
}
