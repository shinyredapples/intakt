<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpGate\Contract;

// The PHP gate's own steps, kept in its contract so the docs and a project's configured command can be compared with one home.
final readonly class PhpGateSteps
{
    // The dependencies first, because the check job's clone holds no installed packages unless it copied a dependency snapshot, and this install then makes the files that depend on the tree.
    public const string INSTALL = 'composer install --no-interaction --no-progress';

    public const string CHECK = 'bin/check --fast';

    public const string PHPUNIT = 'vendor/bin/phpunit';

    // One test file of a bug's regression run, judged from PHPUnit's JUnit report and its status together, because PHPUnit exits 0 when every test skipped and an error such as a missing fixture is no failed assertion. Risky tests fail the run, because the JUnit report does not list them, while a test declared with DoesNotPerformAssertions is not risky and so passes without an assertion. Exit 0 only for a run that executed a test and had no error, failure, skip or risky test; 1 only for failed assertions without an error; 3 for anything else, a report PHPUnit did not write included. %s is the escaped path.
    public const string REGRESSION_TEST = 'rm -f '.self::REGRESSION_REPORT.'; '.self::PHPUNIT.' --fail-on-risky --log-junit '.self::REGRESSION_REPORT.' %s; php -r '.self::REGRESSION_VERDICT.' '.self::REGRESSION_REPORT.' "$?"';

    // A status Composer's own failures do not use, so that a caller reads it as "this head cannot reuse an install" and not as a failed check.
    public const int REFUSED = 78;

    // Before Composer runs, because Composer resolves anew without a lock file or with config.lock off, and because a reused install must hold only packages whose lock entries pin their contents. An explicit null is refused as well: only a missing key means the default. The x after Composer's answer is there because command substitution drops every trailing newline, and a vendor folder whose name ends in one would then be looked for in another folder. The -- is there because php would read a vendor folder starting with a dash as its own option.
    public const string LOCK_GUARD = self::LOCK_GUARD_SCRIPT.' -- "$(composer config vendor-dir 2>/dev/null && printf x)" '.self::REFUSED;

    // Scripts and plugins off, because a reused install must hold only the locked packages and nothing a package ran. The lock's hash is compared around Composer because Composer exits 0 after resolving anew, for example when the lock file has no packages key; it is compared after a failed Composer run too, because Composer writes the new lock before it downloads. Composer's exit code is taken with ||, because a caller's shell running with set -e would otherwise end at a failing Composer, before the comparison.
    public const string LOCKED_INSTALL = self::LOCK_GUARD.' && intakt_lock_before="$('.self::LOCK_HASH.')" && { intakt_composer=0; composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader || intakt_composer=$?; '.self::LOCK_UNCHANGED.' && { (exit "$intakt_composer") && '.self::SOURCE_GUARD.'; }; }';

    // After Composer, because a package Composer cloned from source must still be at the commit the lock names. The guard trusts the checkout's .git folder as git clone wrote it: it removes what the environment can inject (every inherited GIT_ variable, system and global configuration, replace objects, grafts) and refuses redirections a fresh clone never contains (a .git that is not a directory or is a link, a commondir file); further git internals are out of scope unless the locked install itself produces them. The x after Composer's answer and the -- are there for the same reasons as in the lock guard.
    public const string SOURCE_GUARD = self::SOURCE_GUARD_SCRIPT.' -- "$(composer config vendor-dir 2>/dev/null && printf x)" '.self::REFUSED;

    // The whole install, because dump-autoload leaves installed.php naming the head that made the install, while this rewrites it from the checked tree.
    public const string GENERATE = self::INSTALL;

    // Read inside the job image, so the version is the one the check runs on and not the orchestrator's.
    public const string PHP_VERSION_PROBE = "php -r 'echo PHP_VERSION;'";

    // A pre-install-cmd script is refused because a check that copied a snapshot would run it with the vendor folder already in place, while a check without one runs it before any package exists. The vendor folder is resolved by its path segments, because Composer reports it as written and the folder need not exist yet; one outside the folder Composer runs in would leave nothing of the install to keep. Package names are shown JSON-escaped, without JSON_UNESCAPED_UNICODE, so that no character of a name (U+0085 and U+2028 included) can break the one-line message. A refused address is never shown, because a URL can hold credentials that would then reach the check's log. Path types are lowercased, because Composer lowercases a downloader type before choosing it.
    private const string LOCK_GUARD_SCRIPT = <<<'SH_WRAP'
    php -r '$refused = (int) $argv[2];
    $refuse = static function (string $what) use ($refused): never { fwrite(STDERR, $what."\n"); exit($refused); };
    $show = static fn (string $value): string => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $archiveUrl = static fn (string $url): bool => 1 === preg_match("#^https?://\S+$#D", $url);
    $sourceUrl = static fn (string $url, bool $git): bool => 1 === preg_match("#^(https?|ssh|git|git\+ssh|svn)://\S+$#D", $url) || ($git && 1 === preg_match("#^[A-Za-z0-9][A-Za-z0-9._-]*@[A-Za-z0-9][A-Za-z0-9.-]*:\S+$#D", $url));
    $read = static function (string $file) use ($refuse): mixed {
        $text = @file_get_contents($file);
        if (!is_string($text)) { $refuse($file." cannot be read."); }
        try { return json_decode($text, true, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { $refuse($file." is not valid JSON."); }
    };
    if (!is_file("composer.lock")) { fwrite(STDERR, "composer.lock is missing, so there are no locked packages to install.\n"); exit(1); }
    $answer = $argv[1] ?? "";
    $vendor = str_ends_with($answer, "\nx") ? substr($answer, 0, -2) : "";
    if ("" === $vendor) { $refuse("composer config could not report the vendor folder, so it is unknown whether the folder holds an install."); }
    if (str_starts_with($vendor, "/")) { $refuse("The vendor folder ".$show($vendor)." is an absolute path, so the install would not stay in the folder Composer runs in."); }
    $inside = [];
    foreach (explode("/", $vendor) as $part) {
        if ("" === $part || "." === $part) { continue; }
        if (".." !== $part) { $inside[] = $part; continue; }
        if ([] === $inside) { $refuse("The vendor folder ".$show($vendor)." resolves outside the folder Composer runs in."); }
        array_pop($inside);
    }
    if ([] === $inside) { $refuse("The vendor folder ".$show($vendor)." resolves to the folder Composer runs in itself."); }
    $manifest = $read("composer.json");
    $config = is_array($manifest) ? ($manifest["config"] ?? null) : null;
    if (is_array($config) && array_key_exists("lock", $config) && true !== $config["lock"]) { fwrite(STDERR, "composer.json sets config.lock to something other than true, so Composer ignores composer.lock.\n"); exit(1); }
    if (is_file($vendor."/composer/installed.json")) { $refuse("The vendor folder already holds an install (".$show($vendor."/composer/installed.json")."), which may hold more than the lock."); }
    $scripts = is_array($manifest) ? ($manifest["scripts"] ?? []) : [];
    foreach (["pre-install-cmd", "pre-package-install", "post-package-install", "pre-package-update", "post-package-update"] as $event) {
        if (is_array($scripts) && array_key_exists($event, $scripts)) { $refuse("composer.json defines the script ".$show($event).", which the locked install would skip."); }
    }
    $lock = $read("composer.lock");
    foreach (["packages", "packages-dev"] as $list) {
        $packages = is_array($lock) ? ($lock[$list] ?? []) : null;
        if (!is_array($packages)) { $refuse("composer.lock has no valid ".$show($list)." list."); }
        foreach ($packages as $package) {
            $name = is_array($package) && is_string($package["name"] ?? null) ? $package["name"] : null;
            if (null === $name) { $refuse("composer.lock holds a package without a name in ".$show($list)."."); }
            $label = "package ".$show($name);
            $type = strtolower(is_string($package["type"] ?? null) ? $package["type"] : "");
            if (in_array($type, ["composer-plugin", "composer-installer"], true) && "symfony/runtime" !== strtolower($name)) { $refuse("The locked ".$label." is a ".$type.", which would run code during the install."); }
            foreach (["dist", "source"] as $kind) {
                $entry = $package[$kind] ?? null;
                if (null === $entry) { continue; }
                if (!is_array($entry)) { $refuse("The locked ".$label." has a ".$kind." entry that is not an object."); }
                if ("path" === strtolower(is_string($entry["type"] ?? null) ? $entry["type"] : "")) { $refuse("The locked ".$label." is a path package, whose contents are not pinned."); }
                if (array_key_exists("mirrors", $entry)) { $refuse("The locked ".$label." has ".$kind." mirrors, which can serve other bytes."); }
                $url = $entry["url"] ?? null;
                if (!is_string($url) || "" === $url) { $refuse("The locked ".$label." has a ".$kind." entry without a url."); }
                $known = "dist" === $kind ? $archiveUrl($url) : $sourceUrl($url, "git" === strtolower(is_string($entry["type"] ?? null) ? $entry["type"] : ""));
                if (!$known) { $refuse("The locked ".$label." has a ".$kind." url that is not a known remote address."); }
            }
            if (is_array($package["source"] ?? null) && 1 !== preg_match("#^[0-9a-f]{40}$#D", (string) (is_string($package["source"]["reference"] ?? null) ? $package["source"]["reference"] : ""))) { $refuse("The locked ".$label." has a source reference that is not a full commit id."); }
            if (!is_array($package["dist"] ?? null)) { continue; }
            $shasum = $package["dist"]["shasum"] ?? "";
            if (!is_string($shasum) || ("" !== $shasum && 1 !== preg_match("#^[0-9a-f]{40}$#D", $shasum))) { $refuse("The locked ".$label." has a dist checksum that is not 40 lowercase hex digits."); }
            $ownCommit = 1 === preg_match("#^https://[^?\#]*/([0-9a-f]{40})$#D", $package["dist"]["url"], $found) && ($package["dist"]["reference"] ?? null) === $found[1];
            if ("" === $shasum && !$ownCommit) { $refuse("The locked ".$label." has an archive without a checksum that is not named by its own commit id."); }
        }
    }'
    SH_WRAP;

    // Each checkout is found at the install path Composer recorded in installed.json, because Composer clones to the package name as written, plus any target-dir, so a path built from the lock would miss a checkout. The lock guard refuses an existing installed.json, so this one is the locked install's own. A recorded path must stay inside the vendor folder, because a lock file is data. The environment is rebuilt without GIT_ variables, because git follows GIT_COMMON_DIR and similar settings even with an explicit git directory.
    private const string SOURCE_GUARD_SCRIPT = <<<'SH_WRAP'
    php -r '$refused = (int) $argv[2];
    $refuse = static function (string $what) use ($refused): never { fwrite(STDERR, $what."\n"); exit($refused); };
    $show = static fn (string $value): string => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $read = static function (string $file) use ($refuse, $show): mixed {
        $text = @file_get_contents($file);
        if (!is_string($text)) { $refuse($show($file)." cannot be read."); }
        try { return json_decode($text, true, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { $refuse($show($file)." is not valid JSON."); }
    };
    $answer = $argv[1] ?? "";
    $vendor = str_ends_with($answer, "\nx") ? substr($answer, 0, -2) : "";
    if ("" === $vendor) { $refuse("composer config could not report the vendor folder, so it is unknown where the source checkouts are."); }
    $lock = $read("composer.lock");
    $locked = [];
    foreach (["packages", "packages-dev"] as $list) {
        $packages = is_array($lock) ? ($lock[$list] ?? []) : null;
        if (!is_array($packages)) { $refuse("composer.lock has no valid ".$show($list)." list."); }
        foreach ($packages as $package) {
            $name = is_array($package) && is_string($package["name"] ?? null) ? strtolower($package["name"]) : null;
            if (null === $name || 1 !== preg_match("#^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$#D", $name)) { $refuse("composer.lock holds a package without a usable name in ".$show($list)."."); }
            $source = is_array($package["source"] ?? null) ? $package["source"] : [];
            $locked[$name] = [is_string($source["reference"] ?? null) ? $source["reference"] : null, strtolower(is_string($source["type"] ?? null) ? $source["type"] : "")];
        }
    }
    $installed = $read($vendor."/composer/installed.json");
    $packages = is_array($installed) ? ($installed["packages"] ?? null) : null;
    if (!is_array($packages)) { $refuse("The installed.json of the vendor folder has no valid package list."); }
    $environment = array_filter(getenv(), static fn (string $name): bool => !str_starts_with(strtoupper($name), "GIT_"), ARRAY_FILTER_USE_KEY);
    $environment = [...$environment, "GIT_CONFIG_NOSYSTEM" => "1", "GIT_CONFIG_GLOBAL" => "/dev/null", "GIT_NO_REPLACE_OBJECTS" => "1"];
    foreach ($packages as $package) {
        if (!is_array($package) || "source" !== ($package["installation-source"] ?? null)) { continue; }
        $name = strtolower(is_string($package["name"] ?? null) ? $package["name"] : "");
        $label = "package ".$show($name);
        [$reference, $type] = $locked[$name] ?? [null, ""];
        if (null === $reference) { $refuse("The vendor folder holds ".$label." from source, but the lock names no source reference for it."); }
        if ("git" !== $type) { $refuse("The locked ".$label." is installed from a source that is not git, so its commit cannot be checked."); }
        $path = $package["install-path"] ?? null;
        if (!is_string($path) || 1 !== preg_match("#^\.\.(/[^/]+)+$#D", $path) || [] !== array_intersect(array_slice(explode("/", $path), 1), [".", ".."])) { $refuse("The source checkout of ".$label." has an install path outside the vendor folder."); }
        $git = $vendor."/composer/".$path."/.git";
        if (is_link($git)) { $refuse("The source checkout of ".$label." has a .git that is a link."); }
        if (!file_exists($git)) { $refuse("The source checkout of ".$label." has no .git, so its commit cannot be checked."); }
        if (!is_dir($git)) { $refuse("The source checkout of ".$label." has a .git that is not a directory."); }
        if (is_link($git."/commondir") || file_exists($git."/commondir")) { $refuse("The source checkout of ".$label." has a commondir file, so git would read another repository."); }
        $process = proc_open(["git", "--git-dir=".$git, "-c", "core.graftsFile=/dev/null", "rev-parse", "--verify", "HEAD"], [1 => ["pipe", "w"], 2 => ["file", "/dev/null", "w"]], $pipes, null, $environment);
        $head = is_resource($process) ? trim((string) stream_get_contents($pipes[1])) : "";
        $status = is_resource($process) ? proc_close($process) : 1;
        if (0 !== $status) { $refuse("git could not read the HEAD of the source checkout of ".$label."."); }
        if ($head !== $reference) { $refuse("The source checkout of ".$label." is at ".$show($head).", not at the locked commit."); }
    }'
    SH_WRAP;

    private const string LOCK_HASH = <<<'SH'
        php -r 'echo hash_file("sha256", "composer.lock");'
        SH;

    private const string LOCK_UNCHANGED = <<<'SH'
        php -r 'if ($argv[1] !== (is_file("composer.lock") ? hash_file("sha256", "composer.lock") : "")) { fwrite(STDERR, "composer.lock changed during the install, so Composer resolved the dependencies instead of installing the locked packages.\n"); exit(1); }' "$intakt_lock_before"
        SH;

    // Outside the clone, so the report never shows up in the tree under test.
    private const string REGRESSION_REPORT = '"${TMPDIR:-/work}/intakt-regression-junit.xml"';

    // The first testsuite element only, because the outermost one holds the totals of the whole run and a nested one only its own class.
    private const string REGRESSION_VERDICT = <<<'SH'
        '$report = new DOMDocument();
        $suite = is_file($argv[1]) && @$report->load($argv[1], LIBXML_NONET) ? $report->getElementsByTagName("testsuite")->item(0) : null;
        if (!$suite instanceof DOMElement) { exit(3); }
        $count = static fn (string $name): int => (int) $suite->getAttribute($name);
        if ("0" === $argv[2] && $count("tests") > 0 && 0 ===$count("errors") + $count("failures") + $count("skipped")) { exit(0); }
        exit("1" === $argv[2] && $count("failures") > 0 && 0 === $count("errors") ? 1 : 3);'
        SH;
}
