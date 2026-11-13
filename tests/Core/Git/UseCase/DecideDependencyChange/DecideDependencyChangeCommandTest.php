<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\DecideDependencyChange;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Domain\ChangeNotRead;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\UseCase\DecideDependencyChange\DecideDependencyChange;
use Intakt\Core\Git\UseCase\DecideDependencyChange\DecideDependencyChangeCommand;
use Intakt\Core\Git\UseCase\DecideDependencyChange\RevisionFiles;
use Intakt\Core\Git\UseCase\ListProtectedChanges\ChangedPaths;
use Intakt\Plugin\Language\Contract\DependencyDecision;
use Intakt\Plugin\Language\Contract\DependencyFiles;
use Intakt\Plugin\Language\Contract\DependencyFinding;
use Intakt\Plugin\PhpLanguage\Domain\ComposerPolicy;
use Intakt\Plugin\PhpLanguage\Domain\ComposerState;
use Intakt\Plugin\PhpLanguage\Domain\GitHubCommit;
use Intakt\Plugin\PhpLanguage\Domain\LockedPackage;
use Intakt\Plugin\PhpLanguage\Domain\LockedPackageChanges;
use Intakt\Plugin\PhpLanguage\Domain\RequirementChanges;
use Intakt\Plugin\PhpLanguage\Domain\SettingChanges;
use Intakt\Plugin\PhpLanguage\Domain\TrustedPackages;
use Intakt\Plugin\PhpLanguage\Infrastructure\PhpLanguage;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Plugin\PhpLanguage\ComposerFiles;
use Intakt\Tests\Plugin\PhpLanguage\LockedCommits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(DecideDependencyChangeCommand::class)]
#[UsesClass(DecideDependencyChange::class)]
#[UsesClass(RevisionFiles::class)]
#[UsesClass(ChangedPaths::class)]
#[UsesClass(ChangeNotRead::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(PhpLanguage::class)]
#[UsesClass(ComposerPolicy::class)]
#[UsesClass(ComposerState::class)]
#[UsesClass(GitHubCommit::class)]
#[UsesClass(LockedPackage::class)]
#[UsesClass(LockedPackageChanges::class)]
#[UsesClass(RequirementChanges::class)]
#[UsesClass(SettingChanges::class)]
#[UsesClass(TrustedPackages::class)]
#[UsesClass(DependencyDecision::class)]
#[UsesClass(DependencyFiles::class)]
#[UsesClass(DependencyFinding::class)]
final class DecideDependencyChangeCommandTest extends TestCase
{
    private string $mirrors;

    private TemporaryRepository $repository;

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-mirrors-'.bin2hex(random_bytes(6));
        mkdir($this->mirrors);
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('composer.json', '{"require": {"symfony/console": "^8.0"}}');
        $this->repository->checkoutNewBranch('feat/side');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->mirrors);
        $this->repository->remove();
    }

    public function testTheCommandListsTheNewPackagesAndWhyTheOwnerIsNeeded(): void
    {
        $head = $this->repository->commitFile('composer.json', "{\"require\": {\"symfony/console\": \"^8.0\", \"acme/tool\\nowner forged\": \"^1.0\"}}");

        $tester = $this->whenTheCommandRuns($head);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(
            "new package acme/tool\\nowner forged\nowner acme/tool\\nowner forged: is not from a trusted vendor\ndecision: needs the owner\n",
            $tester->getDisplay(),
        );
    }

    public function testTheCommandSaysWhenTheChangeIsRoutine(): void
    {
        $head = $this->repository->commitFile('composer.json', '{"require": {"symfony/console": "^8.1"}}');

        self::assertSame("decision: routine\n", $this->whenTheCommandRuns($head)->getDisplay());
    }

    public function testTheCommandRefusesAHeadThatIsNoFullCommitNameAndExitsWithOne(): void
    {
        $tester = $this->whenTheCommandRuns('feat/side');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"feat/side" is not a full commit name', $tester->getDisplay());
    }

    private function whenTheCommandRuns(string $head): CommandTester
    {
        $this->repository->checkout('main');
        symlink($this->repository->path.'/.git', $this->mirrors.'/alpha.git');
        $decide = new DecideDependencyChange(new ChangedPaths(new HardenedGit()), new RevisionFiles(new HardenedGit()), new PhpLanguage(LockedCommits::upstream(), ['symfony'], ['library']));
        $application = new Application();
        $application->addCommand(new DecideDependencyChangeCommand($decide, new Mirrors($this->mirrors)));
        $tester = new CommandTester($application->find('intakt:dependencies:decide'));
        $tester->execute(['project' => 'alpha', 'head' => $head]);

        return $tester;
    }
}
