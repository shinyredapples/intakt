<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Infrastructure;

// PHP looks in the calling namespace first for an unqualified iterator_to_array(), so a function defined there at run time (not in a file the analyzers see) stops Filesystem::remove() half-way for the rest of the process; only #[RunInSeparateProcess] tests may define it.
final class SnapshotRemovalThatStops
{
    // PHP caches the function a call resolves to, so this must run before the process removes its first folder.
    public static function defineFor(string $armed, string $ran): void
    {
        $code = "<?php\nnamespace Symfony\\Component\\Filesystem;\n"
            ."use Symfony\\Component\\Filesystem\\Exception\\IOException;\n"
            ."use Traversable;\n"
            ."use function iterator_to_array as listGlobally;\n"
            .'function iterator_to_array(Traversable|array $iterator, bool $preserve_keys = true): array {'
            .' $entries = listGlobally($iterator, $preserve_keys);'
            .' if (!is_file('.var_export($armed, true).')) { return $entries; }'
            .' foreach ($entries as $entry) {'
            .' if (is_file($entry) && !is_link($entry)) {'
            .' unlink($entry); touch('.var_export($ran, true).');'
            .' throw new IOException(\'the removal stopped half-way\'); } }'
            .' return $entries; }'."\n";

        $file = tempnam(sys_get_temp_dir(), 'snapshot-removal-');
        file_put_contents($file, $code);
        require $file;
        unlink($file);
    }
}
