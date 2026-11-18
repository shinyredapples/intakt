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
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageSource;
use Intakt\Core\Job\UseCase\BuildJobImage\JobTools;
use Intakt\Core\Job\UseCase\BuildJobImage\PhpProfile;
use Intakt\Core\Job\UseCase\BuildJobImage\ProjectFiles;
use Intakt\Core\Job\UseCase\BuildJobImage\ReviewDockerfile;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(JobImagePlanner::class)]
#[CoversClass(ProjectFiles::class)]
#[UsesClass(CliManifest::class)]
#[UsesClass(Devcontainer::class)]
#[UsesClass(GeneratedDockerfile::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(JobImageNotBuilt::class)]
#[UsesClass(JobImagePlan::class)]
#[UsesClass(JobTools::class)]
#[UsesClass(PhpProfile::class)]
#[UsesClass(ReviewDockerfile::class)]
final class JobImagePlannerTest extends TestCase
{
    private const string COMPOSER_JSON = '{"require": {"php": "^8.5", "ext-intl": "*"}}';

    private TemporaryRepository $repository;

    public function testTheDevcontainerComesBeforeTheDockerfile(): void
    {
        $this->repository->commitFile('composer.json', self::COMPOSER_JSON);
        $this->repository->commitFile('Dockerfile', "FROM php:8.5-cli\n");
        $this->repository->commitFile('.devcontainer/devcontainer.json', '{"build": {"dockerfile": "Dockerfile"}}');

        $plan = $this->planFor();

        self::assertSame(JobImageSource::Devcontainer, $plan->source);
        self::assertSame('.devcontainer', $plan->tree);
        self::assertSame(['intl'], $plan->php?->extensions);
    }

    public function testTheDockerfileIsUsedAsItIsWithoutADevcontainer(): void
    {
        $this->repository->commitFile('composer.json', self::COMPOSER_JSON);
        $this->repository->commitFile('Dockerfile', "FROM php:8.5-cli\n");

        $plan = $this->planFor();

        self::assertSame(JobImageSource::Dockerfile, $plan->source);
        self::assertSame('', $plan->tree);
        self::assertSame('Dockerfile', $plan->dockerfile);
    }

    public function testTheImageIsGeneratedFromComposerJsonAlone(): void
    {
        $this->repository->commitFile('composer.json', self::COMPOSER_JSON);

        $plan = $this->planFor();

        self::assertSame(JobImageSource::Generated, $plan->source);
        self::assertStringStartsWith('FROM php:8.5-cli', (string) $plan->generated);
    }

    public function testADockerfileWithoutComposerJsonIsUsedWithoutAPhpProfile(): void
    {
        $this->repository->commitFile('Dockerfile', "FROM node:24\n");

        $plan = $this->planFor();

        self::assertSame(JobImageSource::Dockerfile, $plan->source);
        self::assertNull($plan->php);
    }

    public function testAProjectWithNothingToBuildFromIsRefused(): void
    {
        $this->repository->commitFile('src/cart.js', 'export {};');

        $this->expectExceptionObject(JobImageNotBuilt::withoutSource());

        $this->planFor();
    }

    public function testAComposerJsonWithoutAPhpRecordIsRefusedEvenBesideADockerfile(): void
    {
        $this->repository->commitFile('composer.json', '{"require": {"ext-intl": "*"}}');
        $this->repository->commitFile('Dockerfile', "FROM php:8.5-cli\n");

        $this->expectExceptionObject(JobImageNotBuilt::withoutPhpRecord());

        $this->planFor();
    }

    public function testOnlyTheCommittedFilesCount(): void
    {
        $this->repository->commitFile('composer.json', self::COMPOSER_JSON);
        new Filesystem()->dumpFile($this->repository->path.'/Dockerfile', "FROM php:7.4-cli\n");

        self::assertSame(JobImageSource::Generated, $this->planFor()->source);
    }

    public function testASymlinkedComposerJsonIsNotRead(): void
    {
        $this->repository->commitFile('real.json', self::COMPOSER_JSON);
        symlink('real.json', $this->repository->path.'/composer.json');
        $this->repository->commitFile('README.md', 'linked');

        $this->expectExceptionObject(JobImageNotBuilt::withoutSource());

        $this->planFor();
    }

    public function testADirectoryWithoutACommitIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/^'.preg_quote($this->repository->path.' is not a git checkout with a commit that git can read: ', '/').'/');

        $this->planFor();
    }

    public function testAFolderInsideACheckoutIsRefused(): void
    {
        $this->repository->commitFile('composer.json', self::COMPOSER_JSON);
        $this->repository->commitFile('sample/README.md', 'sample');

        $this->expectExceptionObject(JobImageNotBuilt::unreadable($this->repository->path.'/sample', 'there is no .git at its top'));

        new JobImagePlanner(new ProjectFiles())->planFor($this->repository->path.'/sample');
    }

    public function testADevcontainerBuildWhoseDockerfileIsNoTextIsRefused(): void
    {
        $this->repository->commitFile('.devcontainer/devcontainer.json', '{"build": {"dockerfile": ["Dockerfile"]}}');

        $this->expectExceptionObject(JobImageNotBuilt::notText('build.dockerfile'));

        $this->planFor();
    }

    // The job runner refuses an image that declares volumes, so the Dockerfile Intakt's own image comes from must declare none.
    public function testIntaktsOwnSourcesPlanItsRootDockerfileWhichDeclaresNoVolume(): void
    {
        $root = dirname(__DIR__, 5);

        foreach (['composer.json', 'Dockerfile'] as $file) {
            $this->repository->commitFile($file, (string) file_get_contents($root.'/'.$file));
        }

        $plan = $this->planFor();

        self::assertSame(JobImageSource::Dockerfile, $plan->source);
        self::assertSame('8.5.12', $plan->php?->rule);
        self::assertFileDoesNotExist($root.'/'.Devcontainer::PATH);
        self::assertDoesNotMatchRegularExpression('/^\s*VOLUME\b/im', (string) file_get_contents($root.'/Dockerfile'));
    }

    public function testAReviewImageOfTheRootDockerfileBuildsTheProjectWithTheJobToolsAdded(): void
    {
        $this->repository->commitFile('composer.json', self::COMPOSER_JSON);
        $this->repository->commitFile('Dockerfile', "FROM php:8.5-cli\nUSER app\n");

        $plan = $this->reviewPlanFor();

        self::assertSame(JobImageSource::Dockerfile, $plan->source);
        self::assertSame('', $plan->tree);
        self::assertSame(ReviewDockerfile::PATH, $plan->dockerfile);
        self::assertSame(ReviewDockerfile::after("FROM php:8.5-cli\nUSER app\n"), $plan->generated);
        self::assertSame(['intl'], $plan->php?->extensions);
    }

    public function testAReviewImageOfADevcontainerBuildReadsItsDockerfileInsideItsContext(): void
    {
        $this->repository->commitFile('.devcontainer/devcontainer.json', '{"build": {"context": "..", "dockerfile": "php.Dockerfile"}}');
        $this->repository->commitFile('.devcontainer/php.Dockerfile', "FROM php:8.4-cli\n");

        $plan = $this->reviewPlanFor();

        self::assertSame('', $plan->tree);
        self::assertSame(ReviewDockerfile::PATH, $plan->dockerfile);
        self::assertStringStartsWith("FROM php:8.4-cli\n\n# Added by Intakt for a review job.\n", (string) $plan->generated);
    }

    public function testAReviewImageOfADevcontainerBuildInASubfolderKeepsItsTree(): void
    {
        $this->repository->commitFile('.devcontainer/devcontainer.json', '{"build": {"dockerfile": "Dockerfile"}}');
        $this->repository->commitFile('.devcontainer/Dockerfile', "FROM php:8.3-cli\n");

        $plan = $this->reviewPlanFor();

        self::assertSame('.devcontainer', $plan->tree);
        self::assertSame(ReviewDockerfile::PATH, $plan->dockerfile);
        self::assertStringStartsWith("FROM php:8.3-cli\n", (string) $plan->generated);
    }

    public function testAReviewImageOfADevcontainerImageAddsTheJobToolsToItsFromLine(): void
    {
        $this->repository->commitFile('.devcontainer/devcontainer.json', '{"image": "mcr.microsoft.com/devcontainers/php:8.5"}');

        $plan = $this->reviewPlanFor();

        self::assertSame(JobImageSource::Devcontainer, $plan->source);
        self::assertNull($plan->tree);
        self::assertSame('Dockerfile', $plan->dockerfile);
        self::assertSame(ReviewDockerfile::after("FROM mcr.microsoft.com/devcontainers/php:8.5\n"), $plan->generated);
    }

    public function testAReviewImageOfAGeneratedImageIsTheGeneratedImage(): void
    {
        $this->repository->commitFile('composer.json', self::COMPOSER_JSON);

        self::assertEquals($this->planFor(), $this->reviewPlanFor());
    }

    public function testAReviewImageOfADevcontainerBuildWithoutItsDockerfileIsRefused(): void
    {
        $this->repository->commitFile('.devcontainer/devcontainer.json', '{"build": {"dockerfile": "Dockerfile"}}');

        $this->expectExceptionObject(JobImageNotBuilt::dockerfileMissing('.devcontainer/Dockerfile'));

        $this->reviewPlanFor();
    }

    public function testAReviewImageOfASymlinkedDockerfileIsRefused(): void
    {
        $this->repository->commitFile('.devcontainer/real.Dockerfile', "FROM php:8.5-cli\n");
        symlink('real.Dockerfile', $this->repository->path.'/.devcontainer/Dockerfile');
        $this->repository->commitFile('.devcontainer/devcontainer.json', '{"build": {"dockerfile": "Dockerfile"}}');

        $this->expectExceptionObject(JobImageNotBuilt::dockerfileMissing('.devcontainer/Dockerfile'));

        $this->reviewPlanFor();
    }

    public function testAReviewImageOfADockerfileThatCannotBeExtendedIsRefused(): void
    {
        $this->repository->commitFile('Dockerfile', "# escape=`\nFROM php:8.5-cli\n");

        $this->expectExceptionObject(JobImageNotBuilt::notExtendable('it sets the escape character to "`"'));

        $this->reviewPlanFor();
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->repository->path);
    }

    private function planFor(): JobImagePlan
    {
        return new JobImagePlanner(new ProjectFiles())->planFor($this->repository->path);
    }

    private function reviewPlanFor(): JobImagePlan
    {
        return new JobImagePlanner(new ProjectFiles())->planFor($this->repository->path, JobImageKind::Review);
    }
}
