<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ContainerLintGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ContainerLintGate::class)]
final class ContainerLintGateTest extends SandboxTestCase
{
    private const string FIXTURE = 'container-mistyped-argument';
    private const string MISTYPED = 'service(Clock::class)';

    public function testAServiceArgumentOfTheWrongTypeFailsTheGate(): void
    {
        $run = $this->kernelSandbox()->check('--only=container');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertStringContainsString('prod: Invalid definition for service "Fixture\Mailer": argument 1 of "Fixture\Mailer::__construct()" accepts "Fixture\Transport", "Fixture\Clock" passed.', $run->findingsOf('container'));
    }

    public function testAServiceArgumentOfTheRightTypePassesTheGate(): void
    {
        $sandbox = $this->kernelSandbox();
        $this->replaceInKernel($sandbox, 'service(Transport::class)');

        $run = $sandbox->check('--only=container');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertTrue($run->passed('container'), $run->output());
    }

    public function testAReferenceToAMissingServiceFailsTheGate(): void
    {
        $sandbox = $this->kernelSandbox();
        $this->replaceInKernel($sandbox, "service('missing.transport')");

        $run = $sandbox->check('--only=container');

        self::assertTrue($run->failed('container'), $run->output());
        self::assertStringContainsString('prod: The service "Fixture\Mailer" has a dependency on a non-existent service "missing.transport".', $run->findingsOf('container'));
    }

    public function testAReferenceToAMissingParameterFailsTheGate(): void
    {
        $sandbox = $this->kernelSandbox();
        $this->replaceInKernel($sandbox, "'%mailer.missing%'");

        $run = $sandbox->check('--only=container');

        self::assertTrue($run->failed('container'), $run->output());
        self::assertStringContainsString('non-existent parameter "mailer.missing"', $run->findingsOf('container'));
    }

    public function testTheFastRunLintsTheContainerToo(): void
    {
        $sandbox = $this->kernelSandbox();
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        $run = $sandbox->check('--only=container', '--fast', '--base=main');

        self::assertTrue($run->failed('container'), $run->output());
    }

    public function testATreeWithoutAConsoleFailsTheGate(): void
    {
        $run = $this->emptySandbox()->check('--only=container');

        self::assertTrue($run->failed('container'), $run->output());
        self::assertStringContainsString('bin/console not found', $run->findingsOf('container'));
    }

    private function kernelSandbox(): Sandbox
    {
        $sandbox = $this->sandboxWith(self::FIXTURE);
        // Because the fixture boots a real kernel, it borrows this repository's installed Symfony instead of shipping its own.
        $sandbox->write('vendor/autoload.php', '<?php return require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).";\n");

        return $sandbox;
    }

    private function replaceInKernel(Sandbox $sandbox, string $argument): void
    {
        $kernel = $sandbox->root.'/src/Kernel.php';
        $sandbox->write('src/Kernel.php', str_replace(self::MISTYPED, $argument, (string) file_get_contents($kernel)));
    }
}
