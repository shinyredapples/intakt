<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Closure;
use Intakt\Core\Build\Contract\TaskKind;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\HandBackFile;
use Intakt\Core\Build\UseCase\BuildTask\PrePushCheck;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\HandBack\Contract\CheckJobOutcome;
use Intakt\Core\HandBack\Contract\CheckJobs;
use Intakt\Core\HandBack\Contract\CheckVerdict;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleFile;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleSecretScan;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\IncomingObjects;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\StrippedCommits;
use Intakt\Core\HandBack\UseCase\RunRegressionTests\RegressionTestFiles;
use Intakt\Core\HandBack\UseCase\RunRegressionTests\RunRegressionTests;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\HandBack\BuildHistory;
use Intakt\Tests\Core\Job\OutputFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(PrePushCheck::class)]
#[CoversClass(RunRegressionTests::class)]
final class PassedBaseSurvivesHeadFailureTest extends TestCase
{
    public function testAPassedBaseStillBlocksWhenTheHeadRunnerThrows(): void
    {
        $this->assertThePassedBaseBlocks(static function (string $script, string $bundles): void {});
    }

    public function testAPassedBaseStillBlocksWhenTheScriptIsGoneBeforeTheHeadRun(): void
    {
        $this->assertThePassedBaseBlocks(static function (string $script, string $bundles): void {
            new Filesystem()->remove($script);
        });
    }

    public function testAPassedBaseStillBlocksWhenTheBundleIsGoneBeforeTheHeadRun(): void
    {
        $this->assertThePassedBaseBlocks(static function (string $script, string $bundles): void {
            new Filesystem()->remove($bundles);
        });
    }

    /**
     * @param Closure(string, string): void $afterTheBaseRun
     */
    private function assertThePassedBaseBlocks(Closure $afterTheBaseRun): void
    {
        $history = new BuildHistory();

        try {
            $history->commitFile('tests/FooTest.php', "<?php\n", 'Add test');
            $bundle = new Filesystem()->readFile($history->bundle('main..task'));
            $checkout = self::createStub(BuildCheckout::class);
            $checkout->method('mirrorOf')->willReturn($history->mirror);
            $checks = self::createStub(CheckJobs::class);
            $checks->method('run')->willReturn(new CheckJobOutcome(CheckVerdict::Passed));
            $fs = new Filesystem();
            $script = $history->folder.'/regression.sh';
            $bundles = $history->folder.'/bundles';
            $fs->copy(dirname(__DIR__, 5).'/docker/job/regression.sh', $script);
            $runner = self::createStub(JobRunner::class);
            $runner->method('run')->willReturnCallback(
                static function (JobSpec $spec) use ($afterTheBaseRun, $script, $bundles): JobResult {
                    if (str_starts_with($spec->id->value, 'regression-head-')) {
                        throw JobRefused::alreadyRunning($spec->id);
                    }

                    $afterTheBaseRun($script, $bundles);

                    return new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of(['regression-result' => "passed\n"]));
                },
            );

            $git = new RepositoryGit(new HardenedGit());
            $gate = new PhpGate();
            $handBacks = new CheckHandBack($git, new BundleFile(), new StrippedCommits(), new IncomingObjects(), new BundleSecretScan('gitleaks'));
            $regression = new RunRegressionTests($handBacks, new RegressionTestFiles($git, $gate), $runner, $gate, $fs, $script);
            $check = new PrePushCheck($checks, new HandBackFile($checkout, $fs, $bundles), $regression, new NullLogger());
            $request = new BuildTaskRequest('alpha', 7, $history->mirror, 'task', $history->work, 'job-image');

            self::assertSame(
                'the new test of a bug also passes on the base, so it proves nothing',
                $check->failureOf($request, $bundle, TaskKind::Bug),
            );
        } finally {
            $history->remove();
        }
    }
}
