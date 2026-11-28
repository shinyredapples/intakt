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

    private const string OWN_NAMESPACE = 'intakt\\';

    // Because Symfony serves a service from the class PHP loads, whose declared spelling only reflection inside the tree knows.
    private const string REFLECT = <<<'PHP'
        require $argv[1];
        $declared = [];
        foreach (json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR) as $class) {
            if (class_exists($class)) {
                $declared[] = new ReflectionClass($class)->getName();
            }
        }
        echo json_encode($declared, JSON_THROW_ON_ERROR);
        PHP;

    private const array OPTIONS = ['--format=json', '--env='.self::ENVIRONMENT, '--no-ansi', '--no-interaction'];

    public function name(): string
    {
        return 'routes';
    }

    public function run(Workspace $workspace): GateResult
    {
        $console = $workspace->root.'/bin/console';

        if (!is_file($console)) {
            return GateResult::failedBecause($this->name(), 'bin/console not found, so the routes cannot be read');
        }

        $cache = ScratchDirectory::create('intakt-routes');

        if (!$cache instanceof ScratchDirectory) {
            return GateResult::failedBecause($this->name(), 'no temporary cache directory could be created, so the routes cannot be read');
        }

        // Because a warmed prod container is never compiled again, it can hold routes the tree no longer has; each run builds the kernel in a cache of its own.
        $environment = ['APP_CACHE_DIR' => $cache->path, 'APP_BUILD_DIR' => $cache->path, 'APP_DEBUG' => false, 'SHELL_VERBOSITY' => false];

        try {
            $listing = $workspace->command([PHP_BINARY, $console, 'debug:router', ...self::OPTIONS], $environment);
            // Because a route may name its controller by service ID, the services' classes say which use case serves it.
            $services = $workspace->command([PHP_BINARY, $console, 'debug:container', ...self::OPTIONS], $environment);
            // Because Symfony serves a route from a hidden service (an ID starting with a dot) too, and --show-hidden lists only those.
            $hiddenServices = $workspace->command([PHP_BINARY, $console, 'debug:container', '--show-hidden', ...self::OPTIONS], $environment);
        } finally {
            $cache->discard();
        }

        if (!$listing->succeeded()) {
            return GateResult::failedBecause($this->name(), 'debug:router exited with '.$listing->exitCode.': '.$listing->firstErrorLine());
        }

        $routes = $this->routesFrom($listing->output);

        if (!$routes instanceof RouteCollection) {
            return GateResult::failedBecause($this->name(), 'debug:router printed no route list in JSON, so the routes cannot be read');
        }

        foreach ([$services, $hiddenServices] as $listed) {
            if (!$listed->succeeded()) {
                return GateResult::failedBecause($this->name(), 'debug:container exited with '.$listed->exitCode.': '.$listed->firstErrorLine());
            }
        }

        $classOfService = $this->classOfServiceFrom($services->output, $hiddenServices->output);

        if (null === $classOfService) {
            return GateResult::failedBecause($this->name(), 'debug:container printed no service list in JSON, so controllers named by service ID cannot be read');
        }

        $reflectedClasses = $this->reflectedClassesOf($workspace, $classOfService);

        if (null === $reflectedClasses) {
            return GateResult::failedBecause($this->name(), 'the services\' classes could not be reflected, so their declared spelling cannot be read');
        }

        return GateResult::withFindings($this->name(), new RouteNamesFollowUseCases($classOfService, $reflectedClasses)->findingsIn($routes));
    }

    /**
     * @param array<string, string> $classOfService
     *
     * @return list<string>|null
     */
    private function reflectedClassesOf(Workspace $workspace, array $classOfService): ?array
    {
        $candidates = array_values(array_unique(array_filter(
            array_map(static fn (string $class): string => ltrim($class, '\\'), $classOfService),
            static fn (string $class): bool => 0 === strncasecmp($class, self::OWN_NAMESPACE, strlen(self::OWN_NAMESPACE)),
        )));

        $scratch = ScratchDirectory::create('intakt-route-classes');

        if (!$scratch instanceof ScratchDirectory) {
            return null;
        }

        try {
            // Because many class names exceed what one command line argument may hold, they go through a file.
            $list = $scratch->path.'/classes.json';
            file_put_contents($list, json_encode($candidates, JSON_THROW_ON_ERROR));
            $reflected = $workspace->command([PHP_BINARY, '-r', self::REFLECT, '--', $workspace->root.'/vendor/autoload.php', $list]);
        } finally {
            $scratch->discard();
        }

        $declared = $reflected->succeeded() ? $this->decoded($reflected->output) : null;

        if (!is_array($declared) || !array_is_list($declared)) {
            return null;
        }

        return array_values(array_filter($declared, is_string(...)));
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
}
