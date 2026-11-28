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

        if (!$services->succeeded()) {
            return GateResult::failedBecause($this->name(), 'debug:container exited with '.$services->exitCode.': '.$services->firstErrorLine());
        }

        $classOfService = $this->classOfServiceFrom($services->output);

        if (null === $classOfService) {
            return GateResult::failedBecause($this->name(), 'debug:container printed no service list in JSON, so controllers named by service ID cannot be read');
        }

        return GateResult::withFindings($this->name(), new RouteNamesFollowUseCases($classOfService)->findingsIn($routes));
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
    private function classOfServiceFrom(string $json): ?array
    {
        $listed = $this->decoded($json);

        if (!is_array($listed) || !is_array($listed['definitions'] ?? null) || !is_array($listed['aliases'] ?? [])) {
            return null;
        }

        $classes = $this->definedClasses($listed['definitions']);

        return $classes + $this->aliasedClasses($listed['aliases'] ?? [], $classes);
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

