<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Symfony\Component\Process\Process;

final class ProjectCopy
{
    // Meant to run once per test class, because no test writes to vendor and copying it costs about a second.
    private const string SCRIPT = <<<'SH'
        set -e
        project=$1
        shift
        cd "$project"
        find . -mindepth 1 -maxdepth 1 ! -name vendor -exec rm -rf {} +
        # Kept only while its installed packages are the checkout's (the same installed.json content), because a folder left by a killed run with the same process id may hold the vendor of an older checkout.
        # The content, not the inode, because the copy has inodes of its own.
        if ! cmp -s vendor/composer/installed.json "$0"/vendor/composer/installed.json 2>/dev/null; then
            rm -rf vendor vendor.partial
            # A copy, not a symlink, because a symlink would resolve the project to the real checkout; not hard links either, because each link changes the inode of the checkout's file, which bin/check --reuse counts.
            # Built under another name, because a copy that stops partway must never count as vendor.
            cp -a "$0"/vendor vendor.partial
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
