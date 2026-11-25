<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\ArchiveBranch;

use DateTimeZone;
use Intakt\Core\Git\Contract\ArchivedBranch;
use Intakt\Core\Git\Contract\BranchArchive;
use Intakt\Core\Git\Contract\BranchNotArchived;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\GitServices;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\MirroredHead;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockFailure;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessFailure;
use Symfony\Component\Process\Process;

final readonly class MirrorBranchArchive implements BranchArchive
{
    private const string REF_PREFIX = 'refs/intakt/archive/';

    private const float TIMEOUT = 60.0;

    public function __construct(
        private HardenedGit $hardened,
        private Filesystem $filesystem,
        private LockFactory $locks,
        private ClockInterface $clock,
        private Mirrors $mirrors,
        #[Autowire(service: GitServices::BRANCH_ARCHIVES)]
        private Mirrors $archives,
    ) {
    }

    public function archive(MirroredHead $head): ArchivedBranch
    {
        try {
            $mirror = $this->mirrors->pathOf($head->project);
            $archive = $this->archives->pathOf($head->project);
            $lock = $this->locks->createLock('git-branch-archive-'.$head->project);
            $lock->acquire(true);
        } catch (ProjectNameRefused|LockFailure $refused) {
            throw BranchNotArchived::because($refused->getMessage(), $refused);
        }

        try {
            return $this->keep($head, $mirror, $archive);
        } catch (IOException|ProcessFailure $failed) {
            throw BranchNotArchived::because($failed->getMessage(), $failed);
        } finally {
            $lock->release();
        }
    }

    private function keep(MirroredHead $head, string $mirror, string $archive): ArchivedBranch
    {
        $this->createArchiveLike($mirror, $archive);
        $this->copyObjects($head->head->commit, $mirror, $archive);
        $moment = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $ref = self::REF_PREFIX.$moment->format('Ymd\THis\Z').'/'.$head->head->branch;
        // The empty old value makes the write create the ref only, so an archive is never moved.
        $this->mustRun(['update-ref', $ref, $head->head->commit, ''], $archive);
        $this->mustHold($ref, $head->head->commit, $archive);

        return new ArchivedBranch($ref, $head->head, $moment);
    }

    private function createArchiveLike(string $mirror, string $archive): void
    {
        if (is_file($archive.'/HEAD') && is_dir($archive.'/objects') && is_dir($archive.'/refs')) {
            return;
        }

        if (!is_dir($mirror)) {
            throw BranchNotArchived::because('the project has no mirror to copy the head from');
        }

        // The mirror's object format, because a fetch cannot copy objects between two formats.
        $format = trim($this->mustRun(['rev-parse', '--show-object-format'], $mirror));
        $this->filesystem->mkdir($archive);
        $this->mustRun(['init', '--quiet', '--object-format='.$format], $archive);
    }

    private function copyObjects(string $commit, string $mirror, string $archive): void
    {
        // A local fetch by name, with no remote saved and no ref written, so the archive never knows an address and never mirrors a remote's refs.
        $call = $this->hardened->callInBareRepository($archive, 'fetch', '--quiet', '--no-tags', '--no-write-fetch-head', '--', $mirror, $commit);
        // HardenedGit allows no protocol, so the fetch names the one it needs: the mirror is a folder of this install.
        $this->run(new GitCall($call->command, $call->directory, $call->environment->with(GIT_ALLOW_PROTOCOL: 'file')));
    }

    /**
     * @param list<string> $arguments
     */
    private function mustRun(array $arguments, string $repository): string
    {
        return $this->run($this->hardened->callInBareRepository($repository, ...$arguments));
    }

    private function mustHold(string $ref, string $commit, string $archive): void
    {
        $held = trim($this->mustRun(['rev-parse', '--verify', '--quiet', $ref.'^{commit}'], $archive));

        if ($held !== $commit) {
            throw BranchNotArchived::because(sprintf('%s does not hold %s', $ref, $commit));
        }
    }

    private function run(GitCall $call): string
    {
        $process = new Process([...$call->command], $call->directory, [...$call->environment], null, self::TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            throw BranchNotArchived::because(trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }
}
