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

        try {
            // Because a warmed prod container is never compiled again, it can hold routes the tree no longer has; each run builds the kernel in a cache of its own.
            $result = $workspace->command(
                [PHP_BINARY, $console, 'debug:router', '--format=json', '--env='.self::ENVIRONMENT, '--no-ansi', '--no-interaction'],
                ['APP_CACHE_DIR' => $cache->path, 'APP_BUILD_DIR' => $cache->path, 'APP_DEBUG' => false, 'SHELL_VERBOSITY' => false],
            );
        } finally {
            $cache->discard();
        }

        if (!$result->succeeded()) {
            return GateResult::failedBecause($this->name(), 'debug:router exited with '.$result->exitCode.': '.$result->firstErrorLine());
        }

        $routes = $this->routesFrom($result->output);

        if (!$routes instanceof RouteCollection) {
            return GateResult::failedBecause($this->name(), 'debug:router printed no route list in JSON, so the routes cannot be read');
        }

        return GateResult::withFindings($this->name(), new RouteNamesFollowUseCases()->findingsIn($routes));
    }

    private function routesFrom(string $json): ?RouteCollection
    {
        try {
            $listed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

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
}
