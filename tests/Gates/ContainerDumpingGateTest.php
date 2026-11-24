<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ContainerDumpingGate;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ContainerDumpingGate::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class ContainerDumpingGateTest extends SandboxTestCase
{
    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRunOnTheFixtureKernel([
            'AMisspelledServiceIdFailsStaticAnalysis' => ['misspelled-service-id', 'src/Lookup.php:17 symfonyContainer.serviceNotFound Service "kernal" is not registered in the container.'],
            'ARegisteredServiceIdPassesStaticAnalysis' => ['registered-service-id'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testTheContainerIsDumpedAgainWhenItsDumpIsGoneFromAFreshCache(): void
    {
        $sandbox = $this->kernelSandbox();
        $first = $sandbox->check('--only=phpstan');
        self::assertTrue($first->passed('phpstan'), $first->output());
        $dump = $sandbox->root.'/var/cache/test/Intakt_KernelTestDebugContainer.xml';
        unlink($dump);

        $run = $sandbox->check('--only=phpstan');

        self::assertTrue($run->passed('phpstan'), $run->output());
        self::assertFileExists($dump);
    }

    public function testATreeIsJudgedByItsOwnContainerWhenTheCallerSharesACacheAnotherTreeBuilt(): void
    {
        $registering = $this->kernelSandbox();
        $removing = $this->kernelSandbox();
        $removing->write('config/services.yaml', "services: {}\n");
        $shared = ['APP_CACHE_DIR' => $registering->root.'/var/cache'];
        $first = $registering->checkWith($shared, '--only=phpstan');
        self::assertTrue($first->passed('phpstan'), $first->output());

        $second = $removing->checkWith($shared, '--only=phpstan');

        self::assertStringContainsString('src/Lookup.php:17 symfonyContainer.serviceNotFound Service "fixture.mailer" is not registered in the container.', $second->findingsOf('phpstan'));
    }

    public function testARegisteredServiceIdPassesStaticAnalysisWhenTheCallerNamesAnotherBuildDirectory(): void
    {
        $sandbox = $this->sandboxWith('registered-service-id');
        $sandbox->bootFixtureKernel();

        $run = $sandbox->checkWith(['APP_BUILD_DIR' => $this->emptySandbox()->root], '--only=phpstan');

        self::assertTrue($run->passed('phpstan'), $run->output());
    }

    public function testATreeIsJudgedByItsFreshContainerWhenTheCallerNamesAnotherBuildDirectory(): void
    {
        $sandbox = $this->kernelSandbox();
        $first = $sandbox->check('--only=phpstan');
        self::assertTrue($first->passed('phpstan'), $first->output());
        $sandbox->write('config/services.yaml', "services: {}\n");

        $second = $sandbox->checkWith(['APP_BUILD_DIR' => $this->emptySandbox()->root], '--only=phpstan');

        self::assertStringContainsString('src/Lookup.php:17 symfonyContainer.serviceNotFound Service "fixture.mailer" is not registered in the container.', $second->findingsOf('phpstan'));
    }

    public function testAFailingConsoleInTheAnalysedTreeFailsStaticAnalysis(): void
    {
        $sandbox = $this->sandboxWith('registered-service-id');
        $sandbox->write('bin/console', '<?php fwrite(STDERR, "the analysed tree has no valid container"); exit(1);');

        $run = $sandbox->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('the test container could not be dumped: the analysed tree has no valid container', $run->findingsOf('phpstan'));
    }

    public function testATreeWithoutAConsoleFailsStaticAnalysis(): void
    {
        $run = $this->sandboxWith('registered-service-id')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('bin/console not found, so the test container cannot be dumped', $run->findingsOf('phpstan'));
    }

    public function testRemovingAServiceInConfigurationAloneFailsTheFastRunOnItsUnchangedCaller(): void
    {
        $sandbox = $this->kernelSandbox();
        $sandbox->commitAll('feat: add the mailer and its caller');
        $sandbox->git('branch', 'baseline');
        $sandbox->write('config/services.yaml', "services: {}\n");
        $sandbox->commitAll('fix: remove the mailer');

        $run = $sandbox->check('--only=phpstan', '--fast', '--base=baseline');

        self::assertStringContainsString('src/Lookup.php:17 symfonyContainer.serviceNotFound Service "fixture.mailer" is not registered in the container.', $run->findingsOf('phpstan'));
    }

    private function kernelSandbox(): Sandbox
    {
        $sandbox = $this->sandboxWith('kernel-with-own-services');
        // Because the fixture boots a real kernel, it borrows this repository's installed Symfony instead of shipping its own.
        $sandbox->write('vendor/autoload.php', '<?php return require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).";\n");
        // Because the kernel writes config/reference.php while it warms up, the tree ignores it in git as this repository does.
        $sandbox->startRepository();

        return $sandbox;
    }
}
