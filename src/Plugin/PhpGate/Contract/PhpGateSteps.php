<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpGate\Contract;

// The PHP gate's own steps, kept in its contract so the docs and a project's configured command can be compared with one home.
final readonly class PhpGateSteps
{
    // The dependencies first, because the check job's clone has no vendor folder and an empty Composer cache.
    public const string INSTALL = 'composer install --no-interaction --no-progress';

    public const string CHECK = 'bin/check --fast';

    // Before Composer runs, because Composer resolves anew without a lock file or with config.lock off. An explicit null is refused as well: only a missing key means the default.
    public const string LOCK_GUARD = <<<'SH'
        php -r 'if (!is_file("composer.lock")) { fwrite(STDERR, "composer.lock is missing, so there are no locked packages to install.\n"); exit(1); }
        $config = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR)["config"] ?? null;
        if (is_array($config) && array_key_exists("lock", $config) && true !== $config["lock"]) { fwrite(STDERR, "composer.json sets config.lock to something other than true, so Composer ignores composer.lock.\n"); exit(1); }'
        SH;

    // Scripts and plugins off, because a reused install must hold only the locked packages and nothing a package ran. The lock's hash is compared around Composer because Composer exits 0 after resolving anew, for example when the lock file has no packages key; it is compared after a failed Composer run too, because Composer writes the new lock before it downloads. Composer's exit code is taken with ||, because a caller's shell running with set -e would otherwise end at a failing Composer, before the comparison.
    public const string LOCKED_INSTALL = self::LOCK_GUARD.' && intakt_lock_before="$('.self::LOCK_HASH.')" && { intakt_composer=0; composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader || intakt_composer=$?; '.self::LOCK_UNCHANGED.' && (exit "$intakt_composer"); }';

    // The whole install, because dump-autoload leaves installed.php naming the head that made the install, while this rewrites it from the checked tree.
    public const string GENERATE = self::INSTALL;

    // Read inside the job image, so the version is the one the check runs on and not the orchestrator's.
    public const string PHP_VERSION_PROBE = "php -r 'echo PHP_VERSION;'";

    private const string LOCK_HASH = <<<'SH'
        php -r 'echo hash_file("sha256", "composer.lock");'
        SH;

    private const string LOCK_UNCHANGED = <<<'SH'
        php -r 'if ($argv[1] !== (is_file("composer.lock") ? hash_file("sha256", "composer.lock") : "")) { fwrite(STDERR, "composer.lock changed during the install, so Composer resolved the dependencies instead of installing the locked packages.\n"); exit(1); }' "$intakt_lock_before"
        SH;
}
