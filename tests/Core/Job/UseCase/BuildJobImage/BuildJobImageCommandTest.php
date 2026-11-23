<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Closure;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\Infrastructure\DockerEngine;
use Intakt\Core\Job\Infrastructure\EngineAnswer;
use Intakt\Core\Job\Infrastructure\ImageBuild;
use Intakt\Core\Job\UseCase\BuildJobImage\BuildContext;
use Intakt\Core\Job\UseCase\BuildJobImage\BuildJobImage;
use Intakt\Core\Job\UseCase\BuildJobImage\BuildJobImageCommand;
use Intakt\Core\Job\UseCase\BuildJobImage\BuildJobImageInput;
use Intakt\Core\Job\UseCase\BuildJobImage\BuiltJobImage;
use Intakt\Core\Job\UseCase\BuildJobImage\CliManifest;
use Intakt\Core\Job\UseCase\BuildJobImage\Devcontainer;
use Intakt\Core\Job\UseCase\BuildJobImage\GeneratedDockerfile;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageKind;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageNotBuilt;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImagePlan;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImagePlanner;
use Intakt\Core\Job\UseCase\BuildJobImage\JobTools;
use Intakt\Core\Job\UseCase\BuildJobImage\PhpInImage;
use Intakt\Core\Job\UseCase\BuildJobImage\PhpProfile;
use Intakt\Core\Job\UseCase\BuildJobImage\ProjectFiles;
use Intakt\Core\Job\UseCase\BuildJobImage\ReviewDockerfile;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Job\FakeDockerEngine;
use PharData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;
use ReflectionFunction;
use SplFileInfo;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

#[CoversClass(BuildJobImageCommand::class)]
#[CoversClass(BuildJobImage::class)]
#[CoversClass(BuildJobImageInput::class)]
#[CoversClass(BuildContext::class)]
#[CoversClass(BuiltJobImage::class)]
#[UsesClass(CliManifest::class)]
#[UsesClass(Devcontainer::class)]
#[UsesClass(DockerEngine::class)]
#[UsesClass(DockerEngineFailed::class)]
#[UsesClass(EngineAnswer::class)]
#[UsesClass(GeneratedDockerfile::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(ImageBuild::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobImageKind::class)]
#[UsesClass(JobImageNotBuilt::class)]
#[UsesClass(JobImagePlan::class)]
#[UsesClass(JobImagePlanner::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobOutput::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(PhpInImage::class)]
#[UsesClass(PhpProfile::class)]
#[UsesClass(JobTools::class)]
#[UsesClass(ProjectFiles::class)]
#[UsesClass(ReviewDockerfile::class)]
final class BuildJobImageCommandTest extends TestCase
{
    private const string IMAGE = 'sha256:2d2f1c0b5f6e4a3b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c0d9e8f7a6b';
    private const string OTHER_IMAGE = 'sha256:7a6b2d2f1c0b5f6e4a3b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c0d9e8f';
    private const string BUILT = '{"stream":"Step 1/6 : FROM php:8.5.12-cli\n"}'."\n".'{"aux":{"ID":"'.self::IMAGE.'"}}'."\n".'{"stream":"Successfully tagged intakt-job/demo:main\n"}'."\n";

    private TemporaryRepository $repository;
    private string $socket = FakeDockerEngine::SOCKET;
    private string $probe = '{"version": "8.5.12", "extensions": ["core", "intl"]}';
    private string $context = '';
    private string $url = '';
    private int $socketAnswersWithStatus = 200;
    private string $contextFile = '';
    private bool $contextLeftOnceSent = true;
    private ?int $disconnectAfter = null;
    private ?BuildJobImage $racing = null;

    /** @var list<MockResponse> */
    private array $requests = [];

    public function testAGeneratedImageIsBuiltAndItsPhpVerified(): void
    {
        $tester = $this->whenTheCommandRuns(self::BUILT);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Job image from composer.json (generated) for PHP 8.5.12 with intl', $this->display($tester));
        self::assertStringContainsString('Successfully tagged intakt-job/demo:main', $this->display($tester));
        self::assertStringContainsString('Image intakt-job/demo:main ('.self::IMAGE.') built: PHP 8.5.12 with every required extension.', $this->display($tester));
    }

    public function testEachBuildProbesTheImageItBuiltEvenWhenAnotherBuildTookTheTagMeanwhile(): void
    {
        $probed = [];
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use (&$probed): JobResult {
            $probed[] = $spec->image;

            return new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput(['php.json' => $this->probe]));
        });
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            $first = 1 === ++$calls;
            if ($first && $this->racing instanceof BuildJobImage) {
                $this->racing->build($this->repository->path, 'intakt-job/demo:main');
            }

            return new MockResponse('{"aux":{"ID":"'.($first ? self::IMAGE : self::OTHER_IMAGE).'"}}'."\n");
        });
        $files = new ProjectFiles();
        $this->racing = new BuildJobImage(new JobImagePlanner($files), new BuildContext($files), new DockerEngine($client, $this->socket), new PhpInImage($runner));

        $this->racing->build($this->repository->path, 'intakt-job/demo:main');

        self::assertSame([self::OTHER_IMAGE, self::IMAGE], $probed);
    }

    public function testAMissingExtensionFailsTheCommandWithItsName(): void
    {
        $tester = $this->whenTheCommandRuns('{"stream":"missing PHP extension: nosuchext\n"}'."\n".'{"error":"The command returned a non-zero code: 1"}'."\n");

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('missing PHP extension: nosuchext', $this->display($tester));
        self::assertStringContainsString('The image intakt-job/demo:main was not built: The command returned a non-zero code: 1', $this->display($tester));
    }

    public function testAnImageWithAnotherPhpFailsAfterTheBuild(): void
    {
        $this->probe = '{"version": "8.4.19", "extensions": ["intl"]}';

        $tester = $this->whenTheCommandRuns(self::BUILT);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('has PHP 8.4.19, which does not meet', $this->display($tester));
    }

    public function testTheProjectsContextHoldsOnlyItsCommittedFiles(): void
    {
        $this->repository->commitFile('Dockerfile', "FROM php:8.5.12-cli\n");
        new Filesystem()->dumpFile($this->repository->path.'/.env.local', 'local');

        $tester = $this->whenTheCommandRuns(self::BUILT);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Job image from Dockerfile', $this->display($tester));
        self::assertSame(['Dockerfile', 'composer.json'], $this->filesInTheSentContext());
    }

    public function testAReviewImageSendsTheProjectsFilesWithItsDockerfileExtendedByTheJobTools(): void
    {
        $this->repository->commitFile('Dockerfile', "FROM php:8.5.12-cli\n");

        $tester = $this->whenTheCommandRuns(self::BUILT, options: ['--kind' => 'review']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([ReviewDockerfile::PATH, 'Dockerfile', 'composer.json'], $this->filesInTheSentContext());
        self::assertSame(ReviewDockerfile::after("FROM php:8.5.12-cli\n"), $this->fileInTheSentContext(ReviewDockerfile::PATH));
        self::assertSame("FROM php:8.5.12-cli\n", $this->fileInTheSentContext('Dockerfile'));
        self::assertStringContainsString('dockerfile='.rawurlencode(ReviewDockerfile::PATH), $this->url);
        self::assertStringContainsString('Image intakt-job/demo:main ('.self::IMAGE.') built: PHP 8.5.12 with every required extension.', $this->display($tester));
    }

    public function testAReviewImageOfAProjectThatCommitsItsOwnReviewDockerfileSendsTheAddedEntryLast(): void
    {
        $this->repository->commitFile('Dockerfile', "FROM php:8.5.12-cli\n");
        $this->repository->commitFile(ReviewDockerfile::PATH, "FROM scratch\n");

        $tester = $this->whenTheCommandRuns(self::BUILT, options: ['--kind' => 'review']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $entries = $this->entriesInTheSentContext();
        self::assertSame(ReviewDockerfile::PATH, array_last($entries));
        self::assertSame([ReviewDockerfile::PATH, ReviewDockerfile::PATH], array_values(array_filter($entries, static fn (string $entry): bool => ReviewDockerfile::PATH === $entry)));
        self::assertSame(ReviewDockerfile::after("FROM php:8.5.12-cli\n"), $this->fileInTheSentContext(ReviewDockerfile::PATH));
    }

    public function testAReviewImageKeepsALongPathAModeAndASymlinkOfTheTree(): void
    {
        $long = str_repeat('a', 100).'/'.str_repeat('b', 100).'/'.str_repeat('c', 100).'/file.txt';
        $this->repository->commitFile('Dockerfile', "FROM php:8.5.12-cli\n");
        new Filesystem()->dumpFile($this->repository->path.'/bin/run', "#!/bin/sh\n");
        chmod($this->repository->path.'/bin/run', 0o755);
        symlink('bin/run', $this->repository->path.'/link');
        $this->repository->commitFile($long, 'long');

        $tester = $this->whenTheCommandRuns(self::BUILT, options: ['--kind' => 'review']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $listing = new Process(['tar', '-tvf', $this->context]);
        $listing->mustRun();
        $output = $listing->getOutput();
        self::assertStringContainsString($long, $output);
        self::assertMatchesRegularExpression('/^-rwx\S+ .* bin\/run$/m', $output);
        self::assertMatchesRegularExpression('/^l.* link -> bin\/run$/m', $output);
        self::assertStringContainsString(ReviewDockerfile::PATH, $output);
    }

    public function testAReviewImageWithAnUnusableTagIsRefusedBeforeAnyPlan(): void
    {
        $this->repository->commitFile('composer.json', '{"require": {"ext-intl": "*"}}');

        $tester = $this->whenTheCommandRuns(self::BUILT, 'Demo:main tag', ['--kind' => 'review']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"Demo:main tag" is not an image tag', $this->display($tester));
    }

    public function testAProjectWithoutAPhpRecordFailsWithTheReason(): void
    {
        $this->repository->commitFile('composer.json', '{"require": {"ext-intl": "*"}}');

        $tester = $this->whenTheCommandRuns(self::BUILT);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('composer.json names no PHP version', $this->display($tester));
        self::assertSame([], $this->requests);
    }

    public function testTheBuildContextIsStreamedFromAFileThatIsRemovedOnceTheRequestIsSent(): void
    {
        $tester = $this->whenTheCommandRuns(self::BUILT);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFalse($this->contextLeftOnceSent, 'The archive is gone before the engine answers.');
        self::assertDirectoryDoesNotExist(dirname($this->contextFile));
    }

    public function testTheBuildContextIsRemovedAlsoWhenTheEngineRefusesTheBuild(): void
    {
        $this->socketAnswersWithStatus = 400;

        $this->whenTheCommandRuns('{"message":"invalid reference format"}');

        self::assertDirectoryDoesNotExist(dirname($this->contextFile));
    }

    public function testTheBuildContextIsRemovedAlsoWhenTheConnectionBreaksDuringTheUpload(): void
    {
        $this->disconnectAfter = 1024;

        $tester = $this->whenTheCommandRuns(self::BUILT);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Connection reset during the upload', $this->display($tester));
        self::assertTrue($this->contextLeftOnceSent, 'The upload broke off before the end of the archive.');
        self::assertDirectoryDoesNotExist(dirname($this->contextFile));
    }

    public function testATagThatIsNoImageReferenceIsRefused(): void
    {
        $tester = $this->whenTheCommandRuns(self::BUILT, 'Demo:main tag');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"Demo:main tag" is not an image tag', $this->display($tester));
    }

    public function testAnUnreachableEngineFailsTheCommandWithItsReason(): void
    {
        $this->socket = '/nonexistent/docker.sock';

        $tester = $this->whenTheCommandRuns(self::BUILT);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('there is no socket at /nonexistent/docker.sock', $this->display($tester));
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('composer.json', '{"require": {"php": ">=8.5.12", "ext-intl": "*"}, "config": {"platform": {"php": "8.5.12"}}}');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->repository->path, $this->context]);
    }

    /**
     * @param array<string, string> $options
     */
    private function whenTheCommandRuns(string $stream, string $tag = 'intakt-job/demo:main', array $options = []): CommandTester
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($stream): MockResponse {
            $this->url = $url;
            $this->context = sys_get_temp_dir().'/intakt-context-'.bin2hex(random_bytes(4)).'.tar';
            self::assertInstanceOf(Closure::class, $options['body'], 'The build context is streamed, not held as a string.');
            $this->contextFile = $this->fileBehind($options['body']);
            self::assertFileExists($this->contextFile);
            new Filesystem()->dumpFile($this->context, $this->bodyRead($options['body'], $this->disconnectAfter));
            $this->contextLeftOnceSent = file_exists(dirname($this->contextFile));
            $response = new MockResponse($stream, ['http_code' => $this->socketAnswersWithStatus]);
            $this->requests[] = $response;

            return $response;
        });
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturn(new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput(['php.json' => $this->probe])));
        $files = new ProjectFiles();
        $build = new BuildJobImage(new JobImagePlanner($files), new BuildContext($files), new DockerEngine($client, $this->socket), new PhpInImage($runner));
        $application = new Application();
        $application->addCommand(new BuildJobImageCommand($build));
        $tester = new CommandTester($application->find('intakt:job:build-image'));
        $tester->execute(['directory' => $this->repository->path, 'tag' => $tag, ...$options]);

        return $tester;
    }

    // From what the body reads, because a glob over the shared temp folder would also match the contexts of builds running beside this one.
    private function fileBehind(Closure $body): string
    {
        // By its value and not its variable's name, so renaming a local of the upload does not break the test.
        $files = array_filter(new ReflectionFunction($body)->getClosureUsedVariables(), static fn (mixed $used): bool => is_string($used) && is_file($used));
        self::assertCount(1, $files);

        return (string) array_first($files);
    }

    private function bodyRead(Closure $body, ?int $disconnectAfter): string
    {
        $read = '';

        while ('' !== ($chunk = $body(512))) {
            $read .= is_string($chunk) ? $chunk : '';

            if (null !== $disconnectAfter && strlen($read) >= $disconnectAfter) {
                $this->contextLeftOnceSent = file_exists(dirname($this->contextFile));

                throw new TransportException('Connection reset during the upload');
            }
        }

        return $read;
    }

    private function fileInTheSentContext(string $name): string
    {
        return new PharData($this->context)[$name]->getContent();
    }

    /**
     * @return list<string>
     */
    private function filesInTheSentContext(): array
    {
        $names = [];
        foreach (new RecursiveIteratorIterator(new PharData($this->context)) as $file) {
            $names[] = $file instanceof SplFileInfo ? $file->getFilename() : self::fail('The build context holds an entry that is no file.');
        }
        sort($names);

        return $names;
    }

    // In the archive's own order through tar, because PharData indexes entries by name and keeps only one of two alike.
    /**
     * @return list<string>
     */
    private function entriesInTheSentContext(): array
    {
        $listing = new Process(['tar', '-tf', $this->context]);
        $listing->mustRun();

        return array_values(array_filter(explode("\n", $listing->getOutput()), static fn (string $entry): bool => '' !== $entry));
    }

    private function display(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }
}
