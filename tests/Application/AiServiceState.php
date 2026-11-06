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
use WeakMap;

// The properties of every object behind the container's Symfony AI services, walked from the services, because a bridge factory builds a platform's providers inside it, where no service definition shows them.
final class AiServiceState
{
    private const array LIBRARIES = ['Symfony\\AI\\', 'Mcp\\'];

    private const int DEPTH = 3;

    // Held, so no object of this snapshot is freed and its id given to another one before the comparison.
    /** @var array<int, object> */
    private array $objects = [];

    /** @var array<string, string> */
    private array $values = [];

    // Walked twice, because building a service or a lazy object on the first walk changes objects that walk had already read.
    public static function of(KernelInterface $kernel): self
    {
        self::walk($kernel);

        return self::walk($kernel);
    }

    /**
     * @return list<string> "Class::$property" of each property whose value differs from this snapshot
     */
    #[NoDiscard]
    public function changedSince(self $before): array
    {
        $changed = [];

        foreach ($before->values as $key => $value) {
            if (array_key_exists($key, $this->values) && $this->values[$key] !== $value) {
                $changed[] = explode('#', $key, 2)[1];
            }
        }

        $changed = array_values(array_unique($changed));
        sort($changed);

        return $changed;
    }

    private static function walk(KernelInterface $kernel): self
    {
        $state = new self();
        $container = $kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        foreach (self::serviceIds($kernel) as $id) {
            if ($container->has($id)) {
                $state->visit($container->get($id));
            }
        }

        return $state;
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

    private function visit(mixed $value): void
    {
        if (is_array($value)) {
            array_walk($value, $this->visit(...));

            return;
        }

        if ($value instanceof LazyObjectInterface) {
            $this->visit($value->initializeLazyObject());

            return;
        }

        if (!is_object($value) || isset($this->objects[spl_object_id($value)]) || !self::isLibrary($value::class)) {
            return;
        }

        $this->objects[spl_object_id($value)] = $value;

        foreach ($this->properties($value) as $property) {
            $key = spl_object_id($value).'#'.$property->getDeclaringClass()->getName().'::$'.$property->getName();
            $this->values[$key] = 'uninitialized';

            if ($property->isInitialized($value)) {
                $this->values[$key] = self::fingerprint($property->getValue($value), 0);
                $this->visit($property->getValue($value));
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
