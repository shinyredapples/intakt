<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpGate\Contract;

// The PHP gate's own steps, kept in its contract so the docs and a project's configured command can be compared with one home.
final readonly class PhpGateSteps
{
    // The dependencies first, because the check job's clone has no vendor folder and an empty Composer cache.
    public const string INSTALL = 'composer install --no-interaction --no-progress';

    public const string CHECK = 'bin/check --fast';

    // A status Composer never exits with, so a caller can tell "this head installs every time" from an install that failed (Composer exits 1 for its own failures too).
    public const int REFUSED = 78;

    // Before Composer runs, because Composer resolves anew without a lock file or with config.lock off (an explicit null too: only a missing key means the default), the split install never runs the root's package-event scripts, and a lock entry that does not pin its contents could install other code for the same lock inputs: a path package, a mirror the guard cannot check, a URL outside the known remote schemes, whose bytes the lock may not name (a stream wrapper such as compress.zlib:// reads a local file; a user@host:path URL is remote only for a git source, because Composer reads an archive URL of that form as a path), a plugin the install skips (symfony/runtime's work is redone by the generation), a checksum Composer skips, an archive with neither a checksum nor an https URL that ends in its locked commit id, or a source whose reference does not look like a full commit id.
    public const string LOCK_GUARD = <<<'SH'
        php -r '$refuse = static function (string $why) use ($argv): never { fwrite(STDERR, $why."\n"); exit((int) $argv[1]); };
        if (!is_file("composer.lock")) { $refuse("composer.lock is missing, so there are no locked packages to install."); }
        $manifest = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR);
        $config = $manifest["config"] ?? null;
        if (is_array($config) && array_key_exists("lock", $config) && true !== $config["lock"]) { $refuse("composer.json sets config.lock to something other than true, so Composer ignores composer.lock."); }
        foreach (["pre-package-install", "post-package-install", "pre-package-update", "post-package-update"] as $event) {
            if (is_array($manifest["scripts"] ?? null) && array_key_exists($event, $manifest["scripts"])) { $refuse("composer.json defines a ".$event." script, which the locked install never runs, so this head installs every time."); }
        }
        $local = static fn (mixed $url, string $schemes, bool $git): bool => !is_string($url) || (1 !== preg_match("~^(?:".$schemes.")://~", $url) && (!$git || 1 !== preg_match("~^[^/:@]+@[^/:]+:~", $url)));
        $lock = json_decode(file_get_contents("composer.lock"), true, 512, JSON_THROW_ON_ERROR);
        foreach ([...$lock["packages"] ?? [], ...$lock["packages-dev"] ?? []] as $package) {
            $name = (string) ($package["name"] ?? "A package without a name");
            $unpinned = static fn (string $why) => $refuse($name." ".$why.", so composer.lock does not pin its contents.");
            if ("path" === ($package["dist"]["type"] ?? null) || "path" === ($package["source"]["type"] ?? null)) { $unpinned("comes from a path repository"); }
            if (isset($package["dist"]["mirrors"]) || isset($package["source"]["mirrors"])) { $unpinned("names mirrors, which Composer may download from first"); }
            if ("composer-plugin" === ($package["type"] ?? null) && "symfony/runtime" !== $name) { $unpinned("is a Composer plugin, which the locked install skips"); }
            if (isset($package["dist"]["url"])) {
                $shasum = $package["dist"]["shasum"] ?? "";
                if ($local($package["dist"]["url"], "https?", false)) { $unpinned("comes from an archive at a local URL"); }
                if ("" !== $shasum && (!is_string($shasum) || 1 !== preg_match("~^[0-9a-f]{40}$~D", $shasum))) { $unpinned("has a checksum that is not 40 lowercase hex characters, which Composer may skip"); }
                if ("" === $shasum && (1 !== preg_match("~^https://[^?#]+/([0-9a-f]{40})$~D", (string) $package["dist"]["url"], $named) || $named[1] !== ($package["dist"]["reference"] ?? null))) { $unpinned("comes from an archive without a checksum whose URL does not end in its locked commit id"); }
            }
            if (isset($package["source"])) {
                if ($local($package["source"]["url"] ?? null, "https?|ssh|git|git\+ssh|svn", "git" ===($package["source"]["type"] ?? null))) { $unpinned("comes from a source at a local URL"); }
                if (1 !== preg_match("~^(?:[0-9a-f]{40}|[0-9a-f]{64})$~D", (string) ($package["source"]["reference"] ?? ""))) { $unpinned("has a source reference that is not a full commit id"); }
            }
        }'
        SH.' '.self::REFUSED;

    // After Composer ran, because the lock entry does not say which hash format the repository uses, so a branch named like a commit id passes the lock guard and only the commit that was checked out can show it: git never resolves such a branch to its own name.
    public const string SOURCE_GUARD = <<<'SH'
        php -r '$refuse = static function (string $why) use ($argv): never { fwrite(STDERR, $why."\n"); exit((int) $argv[2]); };
        $manifest = $argv[1]."/composer/installed.json";
        if (!is_file($manifest)) { $refuse("The install left no vendor/composer/installed.json, so the checked out sources cannot be compared with composer.lock."); }
        $installed = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode(file_get_contents("composer.lock"), true, 512, JSON_THROW_ON_ERROR);
        $locked = array_column([...$lock["packages"] ?? [], ...$lock["packages-dev"] ?? []], "source", "name");
        foreach ($installed["packages"] ?? [] as $package) {
            if ("source" !== ($package["installation-source"] ?? null)) { continue; }
            $source = $locked[$package["name"]] ?? [];
            $head = "git" === ($source["type"] ?? null) ? trim((string) shell_exec("git -C ".escapeshellarg($argv[1]."/composer/".$package["install-path"])." rev-parse --verify --quiet HEAD")) : "";
            if ("" === $head || $head !== ($source["reference"] ?? null)) { $refuse($package["name"]." was installed from source and its checked out commit is not its locked reference, so composer.lock does not pin its contents."); }
        }' "$(composer config --no-interaction --no-plugins --absolute vendor-dir)"
        SH.' '.self::REFUSED;

    // Scripts and plugins off, because a reused install must hold only the locked packages and nothing a package ran. The lock's hash is compared around Composer because Composer exits 0 after resolving anew, for example when the lock file has no packages key; it is compared after a failed Composer run too, because Composer writes the new lock before it downloads. Composer's exit code is taken with ||, because a caller's shell running with set -e would otherwise end at a failing Composer, before the comparison.
    public const string LOCKED_INSTALL = self::LOCK_GUARD.' && intakt_lock_before="$('.self::LOCK_HASH.')" && { intakt_composer=0; composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader || intakt_composer=$?; '.self::LOCK_UNCHANGED.' && (exit "$intakt_composer"); } && '.self::SOURCE_GUARD;

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
