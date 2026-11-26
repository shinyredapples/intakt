<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Symfony\Component\Filesystem\Filesystem;

// git switch and checkout take index.lock before they change a file and write HEAD through HEAD.lock, and give up while either exists. Holding both for every worktree keeps a checkout from naming a branch between a check and a delete.
final readonly class WorktreeLocks
{
    // index.lock comes first, so a checkout that is held off has not touched its working tree yet.
    private const array NAMES = ['index.lock', 'HEAD.lock'];

    public function __construct(private WorktreeGitDirectories $directories = new WorktreeGitDirectories())
    {
    }

    /**
     * @return list<string>
     *
     * @throws ForgeReadFailed
     */
    public function take(string $common): array
    {
        $locks = [];
        foreach ($this->lockFiles($common) as $file) {
            // Created exclusively and never waited for, as git does, because a lock that exists is another git command under way.
            $lock = @fopen($file, 'x');
            if (false === $lock) {
                $this->release($locks);

                throw new ForgeReadFailed(sprintf('%s exists, so a checkout or another git command is under way.', $file));
            }
            fclose($lock);
            $locks[] = $file;
        }

        return $locks;
    }

    /**
     * @param list<string> $locks
     */
    public function release(array $locks): void
    {
        new Filesystem()->remove($locks);
    }

    /**
     * @return list<string>
     */
    private function lockFiles(string $common): array
    {
        $files = [];
        foreach ($this->directories->of($common) as $directory) {
            array_push($files, ...array_map(static fn (string $name): string => $directory.'/'.$name, self::NAMES));
        }

        return $files;
    }
}
