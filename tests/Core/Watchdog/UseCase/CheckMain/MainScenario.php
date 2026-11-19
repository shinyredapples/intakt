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

    public function __construct(string $objectFormat = 'sha1')
    {
        $this->folder = sys_get_temp_dir().'/intakt-watchdog-'.bin2hex(random_bytes(6));
        mkdir($this->folder);
        $this->main = new TemporaryRepository($objectFormat);
    }

    // A folder put first on PATH with an ssh-keygen that only exits, because the watchdog must not read a failed check as a verdict.
    public function sshKeygenExitingWith(int $exit): string
    {
        $folder = $this->folder.'/bin';
        mkdir($folder);
        file_put_contents($folder.'/ssh-keygen', "#!/bin/sh\nexit ".$exit."\n");
        chmod($folder.'/ssh-keygen', 0o755);

        return $folder;
    }

    public function corruptObject(string $hash): void
    {
        $this->main->removeObject($hash);
        file_put_contents($this->main->path.'/.git/objects/'.substr($hash, 0, 2).'/'.substr($hash, 2), 'corrupt');
    }

    public function check(): CheckMain
    {
        return new CheckMain(new MainHistory(new HardenedGit()), new CommitSignature(new Filesystem()));
    }

    // The public key line without its comment, because that is the form an owner registers.
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

    // Written by hand, because git itself never folds a header this long and the classifier must still read the signature after it.
    public function commitSignedByAfterAFoldedHeaderOf(int $continuationLines, string $keyName): string
    {
        $who = 'Test <test@example.invalid> 1700000000 +0000';
        $headers = 'tree '.$this->git(['rev-parse', 'HEAD^{tree}'])."\nparent ".$this->git(['rev-parse', 'HEAD'])."\nauthor ".$who."\ncommitter ".$who
            ."\nmergetag object ".$this->git(['rev-parse', 'HEAD'])."\n type commit\n tag release".str_repeat("\n Release notes", $continuationLines);
        $message = "\n\nSigned merge\n";
        $sign = new Process(['ssh-keygen', '-q', '-Y', 'sign', '-n', 'git', '-f', $this->keyFile($keyName)], null, null, $headers.$message);
        $sign->mustRun();
        $signature = 'gpgsig '.str_replace("\n", "\n ", rtrim($sign->getOutput(), "\n"));
        $write = new Process(['git', 'hash-object', '-t', 'commit', '-w', '--stdin'], $this->main->path, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false], $headers."\n".$signature.$message);
        $write->mustRun();
        $commit = trim($write->getOutput());
        $this->git(['update-ref', 'refs/heads/main', $commit]);

        return $commit;
    }

    public function bareCopy(): string
    {
        $bare = $this->folder.'/bare.git';
        new Process(['git', 'clone', '-q', '--bare', $this->main->path, $bare], null, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false])->mustRun();

        return $bare;
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
