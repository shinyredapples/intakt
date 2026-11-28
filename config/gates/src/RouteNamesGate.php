<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Intakt\Gates\Routing\RouteNamesFollowUseCases;
use JsonException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RouteNamesGate implements RunsTheChangesCode
{
    // Because the shipped routes are the ones a user reaches; the dev and test environments only add other vendors' tools.
    private const string ENVIRONMENT = 'prod';

    // Because Symfony serves a controller from the class PHP has loaded, which the kernel may load while it boots under any spelling, only the booted console knows the declared one.
    private const string REFLECT_AT_SHUTDOWN = <<<'PHP'
        <?php

        register_shutdown_function(static function (): void {
            $declared = [];
            foreach (json_decode((string) file_get_contents(%s), true, 512, JSON_THROW_ON_ERROR) as $class) {
                if (class_exists($class)) {
                    $declared[] = new ReflectionClass($class)->getName();
                }
            }
            file_put_contents(%s, json_encode($declared, JSON_THROW_ON_ERROR));
        });

        PHP;

    private const array OPTIONS = ['--format=json', '--env='.self::ENVIRONMENT, '--no-ansi', '--no-interaction'];

    public function name(): string
    {
        return 'routes';
    }

    public function run(Workspace $workspace): GateResult
    {
        if (!is_file($workspace->root.'/bin/console')) {
            return GateResult::failedBecause($this->name(), 'bin/console not found, so the routes cannot be read');
        }

        $cache = ScratchDirectory::create('intakt-routes');

        if (!$cache instanceof ScratchDirectory) {
            return GateResult::failedBecause($this->name(), 'no temporary cache directory could be created, so the routes cannot be read');
        }

        try {
            $outcome = $this->outcomeIn($workspace, $cache);
        } finally {
            $cache->discard();
        }

        return is_string($outcome) ? GateResult::failedBecause($this->name(), $outcome) : GateResult::withFindings($this->name(), $outcome);
    }

    /**
     * @return list<string>|string
     */
    private function outcomeIn(Workspace $workspace, ScratchDirectory $cache): array|string
    {
        $console = $workspace->root.'/bin/console';
        // Because a warmed prod container is never compiled again, it can hold routes the tree no longer has; each run builds the kernel in a cache of its own.
        $environment = ['APP_CACHE_DIR' => $cache->path, 'APP_BUILD_DIR' => $cache->path, 'APP_DEBUG' => false, 'SHELL_VERBOSITY' => false];

        $listing = $workspace->command([PHP_BINARY, $console, 'debug:router', ...self::OPTIONS], $environment);

        if (!$listing->succeeded()) {
            return 'debug:router exited with '.$listing->exitCode.': '.$listing->firstErrorLine();
        }

        $routes = $this->routesFrom($listing->output);

        if (!$routes instanceof RouteCollection) {
            return 'debug:router printed no route list in JSON, so the routes cannot be read';
        }

        $classOfService = $this->classOfServiceIn($workspace, $console, $environment);

        if (is_string($classOfService)) {
            return $classOfService;
        }

        $controllers = new RouteNamesFollowUseCases($classOfService)->ownControllerClassesIn($routes);
        $reflectedClasses = $this->reflectedClassesOf($workspace, $controllers, $environment);

        if (null === $reflectedClasses) {
            return 'the controllers\' classes could not be reflected in the booted console, so their declared spelling cannot be read';
        }

        return new RouteNamesFollowUseCases($classOfService, $reflectedClasses)->findingsIn($routes);
    }

    private function routesFrom(string $json): ?RouteCollection
    {
        $listed = $this->decoded($json);

        if (!is_array($listed)) {
            return null;
        }

        $routes = new RouteCollection();

        foreach ($listed as $name => $route) {
            if (!is_string($name) || !is_array($route) || !is_string($route['path'] ?? null) || !is_array($route['defaults'] ?? null)) {
                return null;
            }

            $routes->add($name, new Route($route['path'], $route['defaults']));
        }

        return $routes;
    }

    private function decoded(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, string|false> $environment
     *
     * @return array<string, string>|string
     */
    private function classOfServiceIn(Workspace $workspace, string $console, array $environment): array|string
    {
        $listings = [
            // Because a route may name its controller by service ID, the services' classes say which use case serves it.
            $workspace->command([PHP_BINARY, $console, 'debug:container', ...self::OPTIONS], $environment),
            // Because Symfony serves a route from a hidden service (an ID starting with a dot) too, and --show-hidden lists only those.
            $workspace->command([PHP_BINARY, $console, 'debug:container', '--show-hidden', ...self::OPTIONS], $environment),
        ];

        foreach ($listings as $listed) {
            if (!$listed->succeeded()) {
                return 'debug:container exited with '.$listed->exitCode.': '.$listed->firstErrorLine();
            }
        }

        return $this->classOfServiceFrom(...array_map(static fn (ProcessResult $listed): string => $listed->output, $listings))
            ?? 'debug:container printed no service list in JSON, so controllers named by service ID cannot be read';
    }

    /**
     * @return array<string, string>|null
     */
    private function classOfServiceFrom(string ...$listings): ?array
    {
        $definitions = [];
        $aliases = [];

        foreach ($listings as $json) {
            $listed = $this->decoded($json);

            if (!is_array($listed) || !is_array($listed['definitions'] ?? null) || !is_array($listed['aliases'] ?? [])) {
                return null;
            }

            $definitions += $listed['definitions'];
            $aliases += $listed['aliases'] ?? [];
        }

        // Because a visible alias may point at a hidden definition and the other way round, both listings are joined before aliases are resolved.
        $classes = $this->definedClasses($definitions);

        return $classes + $this->aliasedClasses($aliases, $classes);
    }

    /**
     * @param array<mixed> $definitions
     *
     * @return array<string, string>
     */
    private function definedClasses(array $definitions): array
    {
        $classes = [];

        foreach ($definitions as $id => $definition) {
            if (is_string($id) && is_array($definition) && is_string($definition['class'] ?? null)) {
                $classes[$id] = $definition['class'];
            }
        }

        return $classes;
    }

    /**
     * @param array<mixed>          $aliases
     * @param array<string, string> $classes
     *
     * @return array<string, string>
     */
    private function aliasedClasses(array $aliases, array $classes): array
    {
        $aliased = [];

        // Because the compiled container points every alias straight at a definition, even one declared as an alias of an alias.
        foreach ($aliases as $id => $alias) {
            $target = is_array($alias) ? ($alias['service'] ?? null) : null;

            if (is_string($id) && is_string($target) && isset($classes[$target])) {
                $aliased[$id] = $classes[$target];
            }
        }

        return $aliased;
    }

    /**
     * @param list<string>                $controllers
     * @param array<string, string|false> $environment
     *
     * @return list<string>|null
     */
    private function reflectedClassesOf(Workspace $workspace, array $controllers, array $environment): ?array
    {
        if ([] === $controllers) {
            return [];
        }

        $scratch = ScratchDirectory::create('intakt-route-classes');

        if (!$scratch instanceof ScratchDirectory) {
            return null;
        }

        try {
            // Because many class names exceed what one command line argument may hold, they go through a file, and the answer goes to a file so nothing the console prints can mix into it.
            $list = $scratch->path.'/classes.json';
            $answer = $scratch->path.'/declared.json';
            $hook = $scratch->path.'/reflect.php';
            file_put_contents($list, json_encode($controllers, JSON_THROW_ON_ERROR));
            file_put_contents($hook, sprintf(self::REFLECT_AT_SHUTDOWN, var_export($list, true), var_export($answer, true)));
            $booted = $workspace->command([PHP_BINARY, '-d', 'auto_prepend_file='.$hook, $workspace->root.'/bin/console', 'debug:router', ...self::OPTIONS], $environment);
            $declared = $booted->succeeded() && is_file($answer) ? $this->decoded((string) file_get_contents($answer)) : null;
        } finally {
            $scratch->discard();
        }

        if (!is_array($declared) || !array_is_list($declared)) {
            return null;
        }

        return array_values(array_filter($declared, is_string(...)));
    }
}
