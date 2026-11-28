<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\UpdateMirror;

use Intakt\Core\Disk\Contract\DiskSpace;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Contract\WorkTreeInBareRepository;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\UseCase\UpdateMirror\MirrorsOnDisk;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Tests\Core\Git\RecordingClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Process;

#[CoversClass(MirrorsOnDisk::class)]
#[UsesClass(UpdateMirror::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(ProjectNameRefused::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(RepositoryAttributesRefused::class)]
#[UsesClass(WorkTreeInBareRepository::class)]
final class MirrorsOnDiskTest extends TestCase
{
    private string $mirrors;

    private string $scratch;

    public function testABareMirrorOfAProjectIsListedByItsPath(): void
    {
        $this->bare('alpha.git');

        self::assertSame([$this->mirrors.'/alpha.git'], $this->existing());
    }

    public function testAFolderThatHoldsNoRepositoryIsNotListedEvenUnderAMirrorsName(): void
    {
        mkdir($this->mirrors.'/alpha.git', 0o777, true);
        mkdir($this->mirrors.'/backup');

        self::assertSame([], $this->existing());
    }

    public function testABareRepositoryUnderANameNoUpdateWritesToIsNotListed(): void
    {
        $this->bare('backup');
        $this->bare('.alpha.git.0a1b2c3d4e5f');
        $this->bare('..git');

        self::assertSame([], $this->existing());
    }

    public function testARepositoryWithAWorkTreeIsNotListed(): void
    {
        new Process(['git', 'init', '--quiet', $this->mirrors.'/alpha.git'])->mustRun();

        self::assertSame([], $this->existing());
    }

    public function testAFileUnderAMirrorsNameIsNotListed(): void
    {
        mkdir($this->mirrors, 0o777, true);
        touch($this->mirrors.'/alpha.git');

        self::assertSame([], $this->existing());
    }

    public function testAMirrorWhoseCheckIsRefusedIsLeftOutAndTheOthersAreStillListed(): void
    {
        $this->bare('alpha.git');
        $this->bare('beta.git');
        touch($this->mirrors.'/alpha.git/info/attributes');

        self::assertSame([$this->mirrors.'/beta.git'], $this->existing());
    }

    public function testAMirrorsFolderThatDoesNotExistListsNothing(): void
    {
        self::assertSame([], $this->existing());
    }

    public function testBracketsInTheMirrorsPathAreReadLiterally(): void
    {
        $this->mirrors .= '/install[local]/mirrors';
        $this->bare('alpha.git');

        self::assertSame([$this->mirrors.'/alpha.git'], $this->existing());
    }

    protected function setUp(): void
    {
        $this->mirrors = $this->scratch = sys_get_temp_dir().'/intakt-mirrors-on-disk-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
    }

    private function bare(string $name): void
    {
        new Process(['git', 'init', '--bare', '--quiet', $this->mirrors.'/'.$name])->mustRun();
    }

    /**
     * @return list<string>
     */
    private function existing(): array
    {
        $mirrors = new Mirrors($this->mirrors);
        $update = new UpdateMirror(new HardenedGit(), new NetworkGit(new RecordingClock(), new NullLogger()), new Filesystem(), new LockFactory(new InMemoryStore()), $mirrors, self::createStub(DiskSpace::class));

        return new MirrorsOnDisk($update, $mirrors)->existing();
    }
}
