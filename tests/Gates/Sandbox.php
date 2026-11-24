<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use FilesystemIterator;
use Intakt\Gates\Gate;
use Intakt\Gates\GateResult;
use Intakt\Gates\Mode;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\Workspace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;

final readonly class Sandbox
{
    private const string FIXTURES = __DIR__.'/Fixtures';

    private function __construct(public string $root)
    {
    }

    public static function empty(): self
    {
        $root = sys_get_temp_dir().'/intakt-gates-'.bin2hex(random_bytes(6));
        mkdir($root, 0o777, true);

        return new self($root);
    }

    // The same owner in the same process gets the same folder, so the tests of a class can share it without holding it in a property; the process id keeps parallel workers apart.
    public static function ownedBy(string $owner): self
    {
        $root = sys_get_temp_dir().'/intakt-gates-'.hash('xxh128', getmypid().':'.$owner);
        if (!is_dir($root)) {
            mkdir($root, 0o777, true);
        }

        return new self($root);
    }

    public static function withFixture(string $name): self
    {
        $sandbox = self::empty();
        $sandbox->copyFixture(self::FIXTURES.'/'.$name);

        return $sandbox;
    }

    public static function withFixturesInFolders(string ...$names): self
    {
        return self::withFixturesInFoldersUnder('', ...$names);
    }

    public static function withFixturesInFoldersUnder(string $parent, string ...$names): self
    {
        $sandbox = self::empty();

        foreach ($names as $name) {
            $sandbox->copyFixture(self::FIXTURES.'/'.$name, $parent.$name.'/');
        }

        return $sandbox;
    }

    // Keeps var/, so the container an earlier run dumped there serves this fixture too.
    public function replaceFixtureWith(string $name): void
    {
        foreach (new FilesystemIterator($this->root) as $entry) {
            if ($entry instanceof SplFileInfo && 'var' !== $entry->getFilename()) {
                new Filesystem()->remove($entry->getPathname());
            }
        }

        $this->copyFixture(self::FIXTURES.'/'.$name);
    }

    public function write(string $path, string $contents): void
    {
        $target = $this->root.'/'.$path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0o777, true);
        }
        file_put_contents($target, $contents);
    }

    // Because a fixture is a fragment without a kernel, the phpstan gate dumps this repository's test container through a link to its console, into the sandbox's own var/cache: the kernel bakes that folder into the container and cache:warmup writes to the baked one, so a cache shared through links under other paths would write into a removed sandbox.
    public function borrowKernel(): void
    {
        if (!is_dir($this->root.'/bin')) {
            mkdir($this->root.'/bin', 0o777, true);
        }

        $this->symlink('bin/console', dirname(__DIR__, 2).'/bin/console');
    }

    // Because a rule fixture that names a service or a parameter must find it, the sandbox boots the kernel of the fixture `kernel-with-own-services`, which registers its own, instead of this repository's: a change to src/ or config/ services then cannot break the test. It borrows this repository's installed Symfony for its vendor folder.
    public function bootFixtureKernel(): void
    {
        $this->copyFixture(self::FIXTURES.'/kernel-with-own-services');
        unlink($this->root.'/src/Lookup.php');
        $this->write('vendor/autoload.php', '<?php return require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).";\n");
        // Because the kernel writes config/reference.php while it warms up, the tree ignores it in git as this repository does.
        $this->startRepository();
    }

    public function git(string ...$arguments): ProcessResult
    {
        // Without fsync, because a sandbox is a scratch repository that nothing reads after a crash, and side by side workers otherwise wait on each other's disk flushes.
        $identity = ['-c', 'user.name=Gate Test', '-c', 'user.email=gates@example.test', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsync=none'];

        return new ProcessRunner()->run(['git', ...$identity, ...array_values($arguments)], $this->root);
    }

    public function symlink(string $path, string $target): void
    {
        symlink($target, $this->root.'/'.$path);
    }

    /**
     * @param array<string, string> $environment
     */
    public function runScript(string $script, array $environment = []): ProcessResult
    {
        $assignments = array_map(static fn (string $name, string $value): string => $name.'='.$value, array_keys($environment), $environment);

        return new ProcessRunner()->run(['env', ...$assignments, 'bash', '-c', $script], $this->root);
    }

    public function run(string ...$command): ProcessResult
    {
        return new ProcessRunner()->run(array_values($command), $this->root);
    }

    public function commitAll(string $subject): void
    {
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '--allow-empty', '--message', $subject);
    }

    public function startRepository(): void
    {
        $this->git('init', '--quiet', '--initial-branch=main');
    }

    public function promiseMissingObjectsFrom(string $command): void
    {
        // Because a partial clone fetches a missing object lazily from its promisor remote, a command behind an ext:: URL shows whether a call fetches.
        $this->git('config', 'core.repositoryFormatVersion', '1');
        $this->git('config', 'extensions.partialClone', 'origin');
        $this->git('config', 'remote.origin.url', 'ext::'.$command);
        $this->git('config', 'remote.origin.promisor', 'true');
        $this->git('config', 'remote.origin.partialCloneFilter', 'blob:none');
        $this->git('config', 'protocol.ext.allow', 'always');
    }

    public function removeObject(string $hash): void
    {
        unlink($this->root.'/.git/objects/'.substr($hash, 0, 2).'/'.substr($hash, 2));
    }

    public function check(string ...$arguments): CheckRun
    {
        return $this->checkWith([], ...$arguments);
    }

    /**
     * @param array<string, string> $environment
     */
    public function checkWith(array $environment, string ...$arguments): CheckRun
    {
        $home = dirname(__DIR__, 2);
        $result = new ProcessRunner()->run(['php', $home.'/bin/check', '--root='.$this->root, ...array_values($arguments)], $home, $environment);

        return new CheckRun($result);
    }

    // Because starting bin/check costs more than a gate that spawns no tool, its tests run it here and read the line bin/check would print for it.
    public function runGate(Gate $gate, ?string $base = null): CheckRun
    {
        $result = $this->gateResult($gate, $base);

        return new CheckRun(new ProcessResult($result->isPassing() ? 0 : 1, $result->toJson()."\n", ''));
    }

    // Because the line bin/check prints shows only a gate's first ten findings, a run that answers more cases than that reads them all here.
    public function gateResult(Gate $gate, ?string $base = null): GateResult
    {
        return $gate->run(new Workspace($this->root, dirname(__DIR__, 2), Mode::Full, $base));
    }

    public function remove(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($this->root);
    }

    private function copyFixture(string $source, string $folder = ''): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $relative = substr($file->getPathname(), strlen($source) + 1);
                $this->write($folder.(preg_replace('/\.fixture$/', '', $relative) ?? $relative), (string) file_get_contents($file->getPathname()));
            }
        }
    }
}
