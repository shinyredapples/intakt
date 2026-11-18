<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Job\UseCase\BuildJobImage\CliManifest;
use Intakt\Core\Job\UseCase\BuildJobImage\Devcontainer;
use Intakt\Core\Job\UseCase\BuildJobImage\GeneratedDockerfile;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageKind;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageNotBuilt;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImagePlan;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImagePlanner;
use Intakt\Core\Job\UseCase\BuildJobImage\JobTools;
use Intakt\Core\Job\UseCase\BuildJobImage\PhpProfile;
use Intakt\Core\Job\UseCase\BuildJobImage\PlanJobImageCommand;
use Intakt\Core\Job\UseCase\BuildJobImage\ProjectFiles;
use Intakt\Core\Job\UseCase\BuildJobImage\ReviewDockerfile;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(PlanJobImageCommand::class)]
#[UsesClass(CliManifest::class)]
#[UsesClass(Devcontainer::class)]
#[UsesClass(GeneratedDockerfile::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(JobImageKind::class)]
#[UsesClass(JobImageNotBuilt::class)]
#[UsesClass(JobImagePlan::class)]
#[UsesClass(JobImagePlanner::class)]
#[UsesClass(JobTools::class)]
#[UsesClass(PhpProfile::class)]
#[UsesClass(ProjectFiles::class)]
#[UsesClass(ReviewDockerfile::class)]
final class PlanJobImageCommandTest extends TestCase
{
    private TemporaryRepository $repository;

    public function testAGeneratedImageShowsItsSourceAndDockerfile(): void
    {
        $this->repository->commitFile('composer.json', '{"require": {"php": "^8.4", "ext-intl": "*"}}');

        $tester = $this->whenTheCommandRuns();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Job image from composer.json (generated) for PHP ^8.4 with intl.', $tester->getDisplay());
        self::assertStringContainsString('FROM php:8.5-cli', $tester->getDisplay());
    }

    public function testAProjectDockerfileShowsWhereItIsBuilt(): void
    {
        $this->repository->commitFile('.devcontainer/devcontainer.json', '{"build": {"dockerfile": "Dockerfile"}}');

        $tester = $this->whenTheCommandRuns();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Job image from .devcontainer/devcontainer.json, without composer.json to check its PHP against.', $tester->getDisplay());
        self::assertStringContainsString('Builds Dockerfile in .devcontainer.', $tester->getDisplay());
    }

    public function testAReviewImageOfAProjectDockerfileShowsTheDockerfileWithTheJobToolsAndWhereItIsBuilt(): void
    {
        $this->repository->commitFile('composer.json', '{"require": {"php": "^8.4"}}');
        $this->repository->commitFile('Dockerfile', "FROM php:8.4-cli\n");

        $tester = $this->whenTheCommandRuns(['--kind' => 'review']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Job image from Dockerfile for PHP ^8.4.', $tester->getDisplay());
        self::assertStringContainsString("FROM php:8.4-cli\n\n# Added by Intakt for a review job.\nUSER root\n", $tester->getDisplay());
        self::assertStringContainsString('Builds .intakt-review.Dockerfile in the repository root.', $tester->getDisplay());
    }

    public function testAReviewImageOfADevcontainerImageShowsItsDockerfileAlone(): void
    {
        $this->repository->commitFile('.devcontainer/devcontainer.json', '{"image": "mcr.microsoft.com/devcontainers/php:8.5"}');

        $tester = $this->whenTheCommandRuns(['--kind' => 'review']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString("FROM mcr.microsoft.com/devcontainers/php:8.5\n\n# Added by Intakt for a review job.\n", $tester->getDisplay());
        self::assertStringNotContainsString('Builds ', $tester->getDisplay());
    }

    public function testWithoutTheReviewOptionAProjectDockerfileIsShownAsItIs(): void
    {
        $this->repository->commitFile('Dockerfile', "FROM php:8.4-cli\n");

        $tester = $this->whenTheCommandRuns();

        self::assertStringNotContainsString('Added by Intakt', $tester->getDisplay());
        self::assertStringContainsString('Builds Dockerfile in the repository root.', $tester->getDisplay());
    }

    public function testAnUnknownKindIsRefusedWithTheKindsThereAre(): void
    {
        $this->repository->commitFile('Dockerfile', "FROM php:8.4-cli\n");

        $this->expectExceptionMessageMatches('/"build".*"project", "review"/');

        $this->whenTheCommandRuns(['--kind' => 'build']);
    }

    public function testARefusedProjectFailsWithTheReason(): void
    {
        $this->repository->commitFile('README.md', 'no PHP here');

        $tester = $this->whenTheCommandRuns();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('nothing to build a job image from', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->repository->path);
    }

    /**
     * @param array<string, string> $options
     */
    private function whenTheCommandRuns(array $options = []): CommandTester
    {
        $application = new Application();
        $application->addCommand(new PlanJobImageCommand(new JobImagePlanner(new ProjectFiles())));
        $tester = new CommandTester($application->find('intakt:job:plan-image'));
        $tester->execute(['directory' => $this->repository->path, ...$options]);

        return $tester;
    }
}
