<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpGate\Contract;

// The PHP gate's own steps, kept in its contract so the docs and a project's configured command can be compared with one home.
final readonly class PhpGateSteps
{
    // The dependencies first, because the check job's clone has no vendor folder and an empty Composer cache.
    public const string INSTALL = 'composer install --no-interaction --no-progress';

    public const string CHECK = 'bin/check --fast';

    // Before Composer runs, because Composer resolves anew without a lock file or with config.lock off, and the lock entry of a path package, of an archive with neither a checksum nor an https URL that ends in its locked commit id, or of a source whose reference does not look like a full commit id (a branch or tag can move) does not pin its contents.
    public const string LOCK_GUARD = <<<'SH'
        php -r 'if (!is_file("composer.lock")) { fwrite(STDERR, "composer.lock is missing, so there are no locked packages to install.\n"); exit(1); }
        $manifest = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR);
        if (true !== ($manifest["config"]["lock"] ?? true)) { fwrite(STDERR, "composer.json sets config.lock to something other than true, so Composer ignores composer.lock.\n"); exit(1); }
        $lock = json_decode(file_get_contents("composer.lock"), true, 512, JSON_THROW_ON_ERROR);
        foreach ([...$lock["packages"] ?? [], ...$lock["packages-dev"] ?? []] as $package) {
            if ("path" === ($package["dist"]["type"] ?? null) || "path" === ($package["source"]["type"] ?? null)) { fwrite(STDERR, $package["name"]." comes from a path repository, so composer.lock does not pin its contents.\n"); exit(1); }
            if (isset($package["dist"]["url"]) && "" === (string) ($package["dist"]["shasum"] ?? "") && (1 !== preg_match("~^https://[^?#]+/([0-9a-f]{40})$~D", (string) $package["dist"]["url"], $named) || $named[1] !== ($package["dist"]["reference"] ?? null))) { fwrite(STDERR, $package["name"]." comes from an archive without a checksum whose URL does not end in its locked commit id, so composer.lock does not pin its contents.\n"); exit(1); }
            if (isset($package["source"]) && 1 !== preg_match("#^(?:[0-9a-f]{40}|[0-9a-f]{64})$#D", (string) ($package["source"]["reference"] ?? ""))) { fwrite(STDERR, $package["name"]." has a source reference that is not a full commit id, so composer.lock does not pin its contents.\n"); exit(1); }
        }'
        SH;

    // After Composer ran, because the lock entry does not say which hash format the repository uses, so a branch named like a commit id passes the lock guard and only the commit that was checked out can show it: git never resolves such a branch to its own name.
    public const string SOURCE_GUARD = <<<'SH'
        php -r '$manifest = $argv[1]."/composer/installed.json";
        if (!is_file($manifest)) { fwrite(STDERR, "The install left no vendor/composer/installed.json, so the checked out sources cannot be compared with composer.lock.\n"); exit(1); }
        $installed = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode(file_get_contents("composer.lock"), true, 512, JSON_THROW_ON_ERROR);
        $locked = array_column([...$lock["packages"] ?? [], ...$lock["packages-dev"] ?? []], "source", "name");
        foreach ($installed["packages"] ?? [] as $package) {
            if ("source" !== ($package["installation-source"] ?? null)) { continue; }
            $source = $locked[$package["name"]] ?? [];
            $head = "git" === ($source["type"] ?? null) ? trim((string) shell_exec("git -C ".escapeshellarg($argv[1]."/composer/".$package["install-path"])." rev-parse --verify --quiet HEAD")) : "";
            if ("" === $head || $head !== ($source["reference"] ?? null)) { fwrite(STDERR, $package["name"]." was installed from source and its checked out commit is not its locked reference, so composer.lock does not pin its contents.\n"); exit(1); }
        }' "$(composer config --no-interaction --no-plugins --absolute vendor-dir)"
        SH;

    // Scripts and plugins off, because a reused install must hold only the locked packages and nothing a package ran.
    public const string LOCKED_INSTALL = self::LOCK_GUARD.' && composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader && '.self::SOURCE_GUARD;

    // The whole install, because dump-autoload leaves installed.php naming the head that made the install, while this rewrites it from the checked tree.
    public const string GENERATE = self::INSTALL;

    // Read inside the job image, so the version is the one the check runs on and not the orchestrator's.
    public const string PHP_VERSION_PROBE = "php -r 'echo PHP_VERSION;'";
}
