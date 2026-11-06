<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Closure;
use NoDiscard;
use ReflectionObject;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\VarExporter\LazyObjectInterface;
use Symfony\Contracts\Service\ResetInterface;
use WeakMap;

// The properties of every object behind the container's Symfony AI services, walked from the services, because a bridge factory builds a platform's providers inside it, where no service definition shows them.
final class AiServiceState
{
    private const array LIBRARIES = ['Symfony\\AI\\', 'Mcp\\'];

    private const int DEPTH = 3;

    // Held, so no object of this snapshot is freed and its id given to another one while the walk still runs.
    /** @var array<int, object> */
    private array $objects = [];

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
            $state->visit($service, [$id]);
        }

        return $state;
    }

    /**
     * @return list<string>
     */
    #[NoDiscard]
    public function changedSince(self $before): array
    {
        $changed = [];

        foreach ($before->values as $path => $properties) {
            foreach ($properties as $property => $value) {
                if (isset($this->values[$path][$property]) && $this->values[$path][$property] !== $value) {
                    $changed[] = $property;
                }
            }
        }

        return $this->sorted($changed);
    }

    // Every property a request could write: not static, not readonly, on an object that has no reset() for the kernel to call.
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
        assert($container instanceof ContainerInterface);
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
        assert(is_string($dump));
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

    /**
     * @param list<int|string> $path
     */
    private function visit(mixed $value, array $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->visit($item, [...$path, $key]);
            }

            return;
        }

        if ($value instanceof LazyObjectInterface) {
            $this->visit($value->initializeLazyObject(), $path);

            return;
        }

        if (!is_object($value) || isset($this->objects[spl_object_id($value)]) || !self::isLibrary($value::class)) {
            return;
        }

        $this->objects[spl_object_id($value)] = $value;
        $at = json_encode($path, JSON_THROW_ON_ERROR);

        foreach ($this->properties($value) as $property) {
            $name = $property->getDeclaringClass()->getName().'::$'.$property->getName();
            $this->values[$at][$name] = 'uninitialized';

            if (!$property->isReadOnly() && !$value instanceof ResetInterface) {
                $this->mutable[$name] = true;
            }

            if ($property->isInitialized($value)) {
                $this->values[$at][$name] = self::fingerprint($property->getValue($value), 0);
                $this->visit($property->getValue($value), [...$path, $name]);
            }
        }
    }

    /**
     * @return list<ReflectionProperty>
     */
    private function properties(object $object): array
    {
        return array_values(array_filter(new ReflectionObject($object)->getProperties(), static fn (ReflectionProperty $property): bool => !$property->isStatic()));
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
