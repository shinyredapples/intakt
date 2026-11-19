<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMain;
use Intakt\Core\Watchdog\UseCase\CheckMain\CommitSignature;
use Intakt\Core\Watchdog\UseCase\CheckMain\MainHistory;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// A main with real SSH keys, because the watchdog's whole point is the signature check and ssh-keygen must run for it.
final readonly class MainScenario
{
    public TemporaryRepository $main;
    public string $folder;

    public function __construct()
    {
        $this->folder = sys_get_temp_dir().'/intakt-watchdog-'.bin2hex(random_bytes(6));
        mkdir($this->folder);
        $this->main = new TemporaryRepository();
    }

    public function check(): CheckMain
    {
        return new CheckMain(new MainHistory(new HardenedGit()), new CommitSignature(new Filesystem()));
    }

    // Returns the public key line "<type> <base64>" of a new key pair.
    public function newKey(string $name): string
    {
        $file = $this->keyFile($name);
        new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'test', '-f', $file])->mustRun();

        return implode(' ', array_slice(explode(' ', (string) file_get_contents($file.'.pub')), 0, 2));
    }

    public function commitSignedBy(string $keyName): string
    {
        $head = $this->git(['rev-parse', 'HEAD']);
        $tree = $this->git(['rev-parse', 'HEAD^{tree}']);
        $commit = $this->git(['-c', 'gpg.format=ssh', '-c', 'user.signingkey='.$this->keyFile($keyName), 'commit-tree', '-S', '-p', $head, '-m', 'Signed change', $tree]);
        $this->git(['update-ref', 'refs/heads/main', $commit]);

        return $commit;
    }

    public function forceMainTo(string $commit): void
    {
        $this->git(['update-ref', 'refs/heads/main', $commit]);
    }

    public function remove(): void
    {
        $this->main->remove();
        new Filesystem()->remove($this->folder);
    }

    private function keyFile(string $name): string
    {
        return $this->folder.'/'.$name;
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments): string
    {
        $process = new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', ...$arguments], $this->main->path, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
