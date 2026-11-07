<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Symfony\Component\Process\Process;

final class ProjectCopy
{
    // Meant to run once per test class, because no test writes to vendor and linking it cost over half a second per test.
    private const string SCRIPT = <<<'SH'
        set -e
        project=$1
        shift
        cd "$project"
        find . -mindepth 1 -maxdepth 1 ! -name vendor -exec rm -rf {} +
        # Kept only while its installed packages are the checkout's (the same installed.json content), because a folder left by a killed run with the same process id may hold the vendor of an older checkout.
        # The content, not the inode, because the autoload file keeps its inode across an in-place dependency update, so a copy that could not be linked is reused too.
        if ! cmp -s vendor/composer/installed.json "$0"/vendor/composer/installed.json 2>/dev/null; then
            rm -rf vendor vendor.partial
            # Hard links, not a symlink, because a symlink would resolve the project to the real checkout.
            # Built under another name, because a link that fails partway across file systems must never count as vendor.
            cp -al "$0"/vendor vendor.partial 2>/dev/null || { rm -rf vendor.partial && cp -a "$0"/vendor vendor.partial; }
            mv vendor.partial vendor
        fi
        cd "$0"
        cp -a "$@" "$project"/
        SH;

    public static function refresh(string $root, string $project, string ...$paths): void
    {
        new Process(['sh', '-c', self::SCRIPT, $root, $project, ...$paths])->mustRun();
    }
}
