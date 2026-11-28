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

    public function testARouteWhoseControllerIsAClassAndMethodListFailsTheGate(): void
    {
        $sandbox = $this->kernelSandbox();
        $kernel = (string) file_get_contents($sandbox->root.'/src/Kernel.php');
        $listed = str_replace("->controller('Intakt\\Core\\Dashboard\\UseCase\\PlayDemo\\PlayDemoController')", "->defaults(['_controller' => ['Intakt\\Core\\Dashboard\\UseCase\\PlayDemo\\PlayDemoController', '__invoke']])", $kernel);
        self::assertStringContainsString("'_controller' => [", $listed);
        $sandbox->write('src/Kernel.php', $listed);

        $run = $sandbox->check('--only=routes');

        self::assertTrue($run->failed('routes'), $run->output());
        self::assertStringContainsString('Route "dashboard_show_demo" is served by Intakt\Core\Dashboard\UseCase\PlayDemo\PlayDemoController, so it must be named "dashboard_play_demo"', $run->findingsOf('routes'));
    }

    public function testARouteWhoseControllerIsAServiceIdFailsTheGate(): void
    {
        $sandbox = $this->kernelSandbox();
        $kernel = (string) file_get_contents($sandbox->root.'/src/Kernel.php');
        $kernel = str_replace("'http_method_override' => false]);", "'http_method_override' => false]);\n        \$container->services()->set('dashboard.play_demo', 'Intakt\\Core\\Dashboard\\UseCase\\PlayDemo\\PlayDemoController')->public();", $kernel);
        $served = str_replace("->controller('Intakt\\Core\\Dashboard\\UseCase\\PlayDemo\\PlayDemoController')", "->controller('dashboard.play_demo::__invoke')", $kernel);
        self::assertStringContainsString("set('dashboard.play_demo'", $served);
        self::assertStringContainsString("controller('dashboard.play_demo::__invoke')", $served);
        $sandbox->write('src/Kernel.php', $served);

        $run = $sandbox->check('--only=routes');

        self::assertTrue($run->failed('routes'), $run->output());
        self::assertStringContainsString('Route "dashboard_show_demo" is served by Intakt\Core\Dashboard\UseCase\PlayDemo\PlayDemoController, so it must be named "dashboard_play_demo"', $run->findingsOf('routes'));
    }

    public function testAConsoleThatPrintsNoServiceListFailsTheGate(): void
    {
        $sandbox = $this->kernelSandbox();
        $sandbox->write('bin/console', "<?php\necho in_array('debug:router', \$argv, true) ? '{}' : 'no services';\n");

        $run = $sandbox->check('--only=routes');

        self::assertTrue($run->failed('routes'), $run->output());
        self::assertStringContainsString('debug:container printed no service list in JSON', $run->findingsOf('routes'));
    }

    public function testARouteRenamedAfterAnEarlierRunIsReadFromTheChangedKernel(): void
    {
        $sandbox = $this->kernelSandbox();
        $sandbox->write('src/Kernel.php', $this->kernelNamingTheRouteInItsContainer("'dashboard_play_demo'"));
        $warmed = $sandbox->check('--only=routes');
        self::assertTrue($warmed->passed('routes'), $warmed->output());
        $sandbox->write('src/Kernel.php', $this->kernelNamingTheRouteInItsContainer(self::MISNAMED));

        $run = $sandbox->check('--only=routes');

        self::assertTrue($run->failed('routes'), $run->output());
        self::assertStringContainsString('so it must be named "dashboard_play_demo"', $run->findingsOf('routes'));
    }

    public function testARouteListedWithoutItsDefaultsFailsTheGate(): void
    {
        $sandbox = $this->kernelSandbox();
        $sandbox->write('bin/console', "<?php\necho '{\"dashboard_wrong\":{\"path\":\"/probe\",\"defaults\":null}}';\n");

        $run = $sandbox->check('--only=routes');

        self::assertTrue($run->failed('routes'), $run->output());
        self::assertStringContainsString('debug:router printed no route list in JSON', $run->findingsOf('routes'));
    }

    public function testAConsoleThatCannotListTheRoutesFailsTheGate(): void
    {
        $sandbox = $this->kernelSandbox();
        $sandbox->write('src/Kernel.php', "<?php\nthrow new RuntimeException('broken kernel');\n");

        $run = $sandbox->check('--only=routes');

        self::assertTrue($run->failed('routes'), $run->output());
        self::assertStringContainsString('debug:router exited with 255', $run->findingsOf('routes'));
    }

    public function testAConsoleThatPrintsNoRouteListFailsTheGate(): void
    {
        $sandbox = $this->kernelSandbox();
        $sandbox->write('bin/console', "<?php\necho '[\"not a route\"]';\n");

        $run = $sandbox->check('--only=routes');

        self::assertTrue($run->failed('routes'), $run->output());
        self::assertStringContainsString('debug:router printed no route list in JSON', $run->findingsOf('routes'));
    }

    public function testATreeWithoutAConsoleFailsTheGate(): void
    {
        $run = $this->emptySandbox()->check('--only=routes');

        self::assertTrue($run->failed('routes'), $run->output());
        self::assertStringContainsString('bin/console not found', $run->findingsOf('routes'));
    }

    // Because a route name taken from the compiled container stays in a warmed cache, where one written in configureRoutes is read again on every listing.
    private function kernelNamingTheRouteInItsContainer(string $name): string
    {
        $kernel = (string) file_get_contents(__DIR__.'/Fixtures/'.self::FIXTURE.'/src/Kernel.php.fixture');
        $kernel = str_replace("'http_method_override' => false]);", "'http_method_override' => false]);\n        \$container->parameters()->set('fixture.route_name', ".$name.');', $kernel);

        return str_replace("\$routes->add('dashboard_show_demo'", "\$routes->add(\$this->getContainer()->getParameter('fixture.route_name')", $kernel);
    }

    private function kernelSandbox(): Sandbox
    {
        $sandbox = $this->sandboxWith(self::FIXTURE);
        // Because the fixture boots a real kernel, it borrows this repository's installed Symfony instead of shipping its own.
        $sandbox->write('vendor/autoload.php', '<?php return require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).";\n");

        return $sandbox;
    }
}
