<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\RouteNamesGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RouteNamesGate::class)]
final class RouteNamesGateTest extends SandboxTestCase
{
    private const string FIXTURE = 'routes-misnamed';
    private const string MISNAMED = "'dashboard_show_demo'";

    public function testARouteNotNamedAfterItsModuleAndUseCaseFailsTheGate(): void
    {
        $run = $this->kernelSandbox()->check('--only=routes');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertStringContainsString('Route "dashboard_show_demo" is served by Intakt\Core\Dashboard\UseCase\PlayDemo\PlayDemoController, so it must be named "dashboard_play_demo"', $run->findingsOf('routes'));
    }

    public function testARouteNamedAfterItsModuleAndUseCasePassesTheGate(): void
    {
        $sandbox = $this->kernelSandbox();
        $kernel = $sandbox->root.'/src/Kernel.php';
        $sandbox->write('src/Kernel.php', str_replace(self::MISNAMED, "'dashboard_play_demo'", (string) file_get_contents($kernel)));

        $run = $sandbox->check('--only=routes');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertTrue($run->passed('routes'), $run->output());
    }

    public function testAConsoleThatCannotListTheRoutesFailsTheGate(): void
    {
        $sandbox = $this->kernelSandbox();
        $sandbox->write('src/Kernel.php', "<?php\nthrow new RuntimeException('broken kernel');\n");

        $run = $sandbox->check('--only=routes');

        self::assertTrue($run->failed('routes'), $run->output());
        self::assertStringContainsString('debug:router exited with 255', $run->findingsOf('routes'));
    }

    public function testATreeWithoutAConsoleFailsTheGate(): void
    {
        $run = $this->emptySandbox()->check('--only=routes');

        self::assertTrue($run->failed('routes'), $run->output());
        self::assertStringContainsString('bin/console not found', $run->findingsOf('routes'));
    }

    private function kernelSandbox(): Sandbox
    {
        $sandbox = $this->sandboxWith(self::FIXTURE);
        // Because the fixture boots a real kernel, it borrows this repository's installed Symfony instead of shipping its own.
        $sandbox->write('vendor/autoload.php', '<?php return require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).";\n");

        return $sandbox;
    }
}
