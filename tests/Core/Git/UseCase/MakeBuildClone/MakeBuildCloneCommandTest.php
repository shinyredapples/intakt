<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\MakeBuildClone;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\UseCase\MakeBuildClone\BuildCloneNotMade;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildCloneCommand;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildCloneInput;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(MakeBuildCloneCommand::class)]
#[UsesClass(MakeBuildClone::class)]
#[UsesClass(MakeBuildCloneInput::class)]
#[UsesClass(BuildCloneNotMade::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(UpdateMirror::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
final class MakeBuildCloneCommandTest extends TestCase
{
    private string $mirrors;

    private string $clone;

    private TemporaryRepository $remote;

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-mirrors-'.bin2hex(random_bytes(6));
        $this->clone = $this->mirrors.'-clone';
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->mirrors, $this->clone]);
        $this->remote->remove();
    }

    public function testTheCommandPrintsThePathOfTheBuildCloneAndExitsWithZero(): void
    {
        $network = new NetworkGit(new RecordingClock(), new NullLogger());
        new UpdateMirror(new HardenedGit(), $network, new Filesystem(), new LockFactory(new InMemoryStore()), new Mirrors($this->mirrors))->update('alpha', $this->remote->path);

        $tester = $this->whenTheCommandRuns('alpha', 'feat/task');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('clone '.$this->clone, $tester->getDisplay());
    }

    public function testTheCommandPrintsTheReasonForAProjectWithoutAMirrorAndExitsWithOne(): void
    {
        $tester = $this->whenTheCommandRuns('alpha', 'feat/task');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('has no mirror yet', $this->displayOnOneLine($tester));
    }

    public function testTheCommandPrintsTheReasonForARefusedProjectNameAndExitsWithOne(): void
    {
        $tester = $this->whenTheCommandRuns('../alpha', 'feat/task');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is not a project name that can be a folder name', $this->displayOnOneLine($tester));
    }

    /**
     * The error block wraps at the terminal width, which depends on how long the temporary path is.
     */
    private function displayOnOneLine(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function whenTheCommandRuns(string $project, string $branch): CommandTester
    {
        $application = new Application();
        $application->addCommand(new MakeBuildCloneCommand(new MakeBuildClone(new HardenedGit(), new Filesystem(), new Mirrors($this->mirrors))));
        $tester = new CommandTester($application->find('intakt:build:clone'));
        $tester->execute(['project' => $project, 'branch' => $branch, 'directory' => $this->clone]);

        return $tester;
    }
}
