<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpGate\Contract;

// The PHP gate's own steps, kept in its contract so the docs and a project's configured command can be compared with one home.
final readonly class PhpGateSteps
{
    // The dependencies first, because the check job's clone has no vendor folder and an empty Composer cache.
    public const string INSTALL = 'composer install --no-interaction --no-progress';

    public const string CHECK = 'bin/check --fast';

    // Before Composer runs, because Composer resolves anew without a lock file or with config.lock off, and the lock entry of a path package or of a local archive without a checksum does not pin its contents.
    public const string LOCK_GUARD = <<<'SH'
        php -r 'if (!is_file("composer.lock")) { fwrite(STDERR, "composer.lock is missing, so there are no locked packages to install.\n"); exit(1); }
        $manifest = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR);
        if (true !== ($manifest["config"]["lock"] ?? true)) { fwrite(STDERR, "composer.json sets config.lock to something other than true, so Composer ignores composer.lock.\n"); exit(1); }
        $lock = json_decode(file_get_contents("composer.lock"), true, 512, JSON_THROW_ON_ERROR);
        foreach ([...$lock["packages"] ?? [], ...$lock["packages-dev"] ?? []] as $package) {
            if ("path" === ($package["dist"]["type"] ?? null) || "path" === ($package["source"]["type"] ?? null)) { fwrite(STDERR, $package["name"]." comes from a path repository, so composer.lock does not pin its contents.\n"); exit(1); }
            if (isset($package["dist"]["url"]) && 1 !== preg_match("#^https?://#i", (string) $package["dist"]["url"]) && "" === (string) ($package["dist"]["shasum"] ?? "")) { fwrite(STDERR, $package["name"]." comes from a local archive without a checksum, so composer.lock does not pin its contents.\n"); exit(1); }
        }'
        SH;

    // Scripts and plugins off, because a reused install must hold only the locked packages and nothing a package ran.
    public const string LOCKED_INSTALL = self::LOCK_GUARD.' && composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader';

    // The whole install, because dump-autoload leaves installed.php naming the head that made the install, while this rewrites it from the checked tree.
    public const string GENERATE = self::INSTALL;

    // Read inside the job image, so the version is the one the check runs on and not the orchestrator's.
    public const string PHP_VERSION_PROBE = "php -r 'echo PHP_VERSION;'";
}
