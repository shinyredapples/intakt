<?php

declare(strict_types=1);

namespace Intakt\Gates\Routing;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\String\UnicodeString;

final class RouteNamesFollowUseCases
{
    private const string CONTROLLER = '/^Intakt\\\\(?:Core|Plugin)\\\\(\w+)\\\\UseCase\\\\(\w+)\\\\/';

    private const string OWN_NAMESPACE = 'Intakt\\';

    /**
     * @return list<string>
     */
    public function findingsIn(RouteCollection $routes): array
    {
        $findings = [];

        foreach ($routes as $name => $route) {
            $controller = $this->controllerClassOf($route);

            if (null === $controller || !str_starts_with($controller, self::OWN_NAMESPACE)) {
                continue;
            }

            $finding = $this->findingFor($name, $controller);

            if (null !== $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function controllerClassOf(Route $route): ?string
    {
        $controller = $route->getDefault('_controller');

        // Because a route may also name its controller as [class, method], which serves the route just like Class::method.
        if (is_array($controller) && array_is_list($controller) && 2 === count($controller) && is_string($controller[0])) {
            $controller = $controller[0];
        }

        // Because a route file may write the class with a leading backslash, which names the same class.
        return is_string($controller) ? ltrim(explode('::', $controller)[0], '\\') : null;
    }

    private function findingFor(string $name, string $controller): ?string
    {
        if (1 !== preg_match(self::CONTROLLER, $controller, $parts)) {
            return sprintf('Route "%s" is served by %s, which is not in a UseCase/<Name>/ folder directly inside its module (src/Core/<Module>/ or src/Plugin/<Module>/): move the controller into one, so the route name can be <module>_<use_case>.', $name, $controller);
        }

        $expected = $this->snake($parts[1]).'_'.$this->snake($parts[2]);

        if ($expected === $name) {
            return null;
        }

        return sprintf('Route "%s" is served by %s, so it must be named "%s" (<module>_<use_case>): rename the route, or move the controller into the UseCase/<Name>/ folder the name says.', $name, $controller, $expected);
    }

    private function snake(string $word): string
    {
        return new UnicodeString($word)->snake()->toString();
    }
}
