<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Closure;
use NoDiscard;
use ReflectionObject;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\VarExporter\LazyObjectInterface;
use Symfony\Contracts\Service\ResetInterface;
use WeakMap;

// The properties of every object behind the container's Symfony AI services, walked from the services, because a bridge factory builds a platform's providers inside it, where no service definition shows them.
final class AiServiceState
{
    private const array LIBRARIES = ['Symfony\\AI\\', 'Mcp\\'];

    private const int DEPTH = 3;

    // Keyed by the path from the service, not by object, so a reset that puts a new object in place is compared with the one it replaced.
    /** @var array<string, array<string, string>> */
    private array $values = [];

    /** @var array<string, true> */
    private array $mutable = [];

    // Walked twice, because building a service or a lazy object on the first walk changes objects that walk had already read.
    public static function of(KernelInterface $kernel): self
    {
        $services = self::services($kernel);
        self::ofServices($services);

        return self::ofServices($services);
    }

    /**
     * @param array<string, object> $services
     */
    public static function ofServices(array $services): self
    {
        $state = new self();

        foreach ($services as $id => $service) {
            $state->visit($service, Trail::fromService($id));
        }

        return $state;
    }

    // A property only one walk recorded, at a path only it reached or at a shared path, counts as changed, because two equal rounds with a reset between them reach the same objects: a path or a property that came or went is itself state a round left behind.
    /**
     * @return list<string>
     */
    #[NoDiscard]
    public function changedSince(self $before): array
    {
        $changed = [];

        foreach (array_keys($before->values + $this->values) as $path) {
            foreach (array_keys(($before->values[$path] ?? []) + ($this->values[$path] ?? [])) as $property) {
                if (($before->values[$path][$property] ?? null) !== ($this->values[$path][$property] ?? null)) {
                    $changed[] = $property;
                }
            }
        }

        return $this->sorted($changed);
    }

    // Static and readonly properties, and objects with a reset(), are left out, because a request cannot write the first two and the kernel's reset clears the last.
    /**
     * @return list<string>
     */
    #[NoDiscard]
    public function mutableWithoutReset(): array
    {
        return $this->sorted(array_keys($this->mutable));
    }

    /**
     * @return array<string, object>
     */
    private static function services(KernelInterface $kernel): array
    {
        $container = $kernel->getContainer()->get('test.service_container');
        $services = [];

        foreach (self::serviceIds($kernel) as $id) {
            $service = $container->has($id) ? $container->get($id) : null;

            if (is_object($service)) {
                $services[$id] = $service;
            }
        }

        return $services;
    }

    /**
     * @return list<string>
     */
    private static function serviceIds(KernelInterface $kernel): array
    {
        // The definitions of the compiled container, which the framework keeps beside its debug dump for debug:container.
        $dump = $kernel->getContainer()->getParameter('debug.container.dump');
        $built = unserialize((string) file_get_contents(substr_replace($dump, '.ser', -4)), ['allowed_classes' => true]);
        assert($built instanceof ContainerBuilder);
        $ids = [];

        foreach ($built->getDefinitions() as $id => $definition) {
            if (self::isLibrary((string) $definition->getClass()) || str_starts_with($id, 'ai.') || str_starts_with($id, 'mcp.')) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private static function isLibrary(string $class): bool
    {
        return array_any(self::LIBRARIES, static fn (string $namespace): bool => str_starts_with($class, $namespace));
    }

    // An object is skipped only while it is an ancestor on the current path (a cycle), not once it was reached elsewhere: an object two properties share is recorded under both paths, so a reset that replaces the first reference with an empty object while the second keeps the used one still shows under the second path.
    private function visit(mixed $value, Trail $trail): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->visit($item, $trail->into($key));
            }

            return;
        }

        if ($value instanceof LazyObjectInterface) {
            $this->visit($value->initializeLazyObject(), $trail);

            return;
        }

        if (!is_object($value) || $trail->passed($value) || !self::isLibrary($value::class)) {
            return;
        }

        foreach ($this->properties($value) as $property) {
            $this->record($value, $property, $trail->through($value));
        }
    }

    /**
     * @return list<ReflectionProperty>
     */
    private function properties(object $object): array
    {
        return array_values(array_filter(new ReflectionObject($object)->getProperties(), static fn (ReflectionProperty $property): bool => !$property->isStatic()));
    }

    private function record(object $object, ReflectionProperty $property, Trail $trail): void
    {
        $at = $trail->key();
        $name = $property->getDeclaringClass()->getName().'::$'.$property->getName();
        $this->values[$at][$name] = 'uninitialized';

        if (!$property->isReadOnly() && !$object instanceof ResetInterface) {
            $this->mutable[$name] = true;
        }

        if ($property->isInitialized($object)) {
            $this->values[$at][$name] = self::fingerprint($property->getValue($object), 0);
            $this->visit($property->getValue($object), $trail->into($name));
        }
    }

    /**
     * @param array<string> $names
     *
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    // By content to a few levels, not by identity, because a reset that puts a fresh, empty object in place of the used one has left nothing behind.
    private static function fingerprint(mixed $value, int $depth): string
    {
        return match (true) {
            $value instanceof Closure => 'Closure',
            // A library object is compared on its own walk, so its owner does not report the same change again.
            is_object($value) && ($depth > self::DEPTH || self::isLibrary($value::class)) => $value::class,
            // A WeakMap keeps its entries outside its properties; other countables are left uncounted, because a store counts by querying its database.
            is_object($value) => $value::class.($value instanceof WeakMap ? '('.count($value).')' : '').self::fingerprint(get_mangled_object_vars($value), $depth + 1),
            !is_array($value) => var_export($value, true),
            $depth > self::DEPTH => 'array('.count($value).')',
            default => '['.implode(',', array_map(static fn (int|string $key, mixed $item): string => $key.'=>'.self::fingerprint($item, $depth + 1), array_keys($value), $value)).']',
        };
    }
}
