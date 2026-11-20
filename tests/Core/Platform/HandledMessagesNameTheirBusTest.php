<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Core\Platform\Contract\Bus;
use Intakt\Core\Platform\Contract\CommandMessage;
use Intakt\Core\Platform\Contract\QueryMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;

#[CoversClass(CommandMessage::class)]
#[CoversClass(QueryMessage::class)]
#[UsesClass(AsCommandHandler::class)]
#[UsesClass(AsQueryHandler::class)]
#[UsesClass(Bus::class)]
final class HandledMessagesNameTheirBusTest extends TestCase
{
    private const array SCANNED = [
        'src' => 'Intakt\\',
        'tests/Core/Platform/Handler' => 'Intakt\\Tests\\Core\\Platform\\Handler\\',
    ];

    #[DataProvider('handledMessages')]
    public function testEveryHandledMessageImplementsTheMarkerOfTheBusItsHandlerNames(string $message, Bus $bus): void
    {
        $marker = match ($bus) {
            Bus::Command => CommandMessage::class,
            Bus::Query => QueryMessage::class,
        };

        self::assertTrue(is_a($message, $marker, true), sprintf('%s is handled on %s, so it implements %s.', $message, $bus->value, $marker));
    }

    public function testTheScanFindsHandlersOfBothBuses(): void
    {
        $buses = array_unique(array_map(static fn (array $case): string => $case[1]->value, self::handledMessages()));
        sort($buses);

        self::assertSame([Bus::Command->value, Bus::Query->value], $buses);
    }

    public function testTheScanFindsAMessageNamedOnlyByHandles(): void
    {
        self::assertArrayHasKey('Intakt\\Tests\\Core\\Platform\\Handler\\PingHandler handles Intakt\\Tests\\Core\\Platform\\Handler\\Ping on command.bus', self::handledMessages());
    }

    /**
     * @return array<string, array{string, Bus}>
     */
    public static function handledMessages(): array
    {
        $cases = [];
        foreach (self::handlerClasses() as $handler) {
            foreach (self::handlerAttributes($handler) as [$attribute, $method]) {
                $message = $attribute->handles ?? self::firstParameterType($handler, $attribute->method ?? $method);
                $bus = Bus::from((string) $attribute->bus);
                $cases[sprintf('%s handles %s on %s', $handler->getName(), $message, $bus->value)] = [$message, $bus];
            }
        }

        return $cases;
    }

    /**
     * @return list<ReflectionClass<object>>
     */
    private static function handlerClasses(): array
    {
        $classes = [];
        foreach (self::SCANNED as $directory => $namespace) {
            $root = dirname(__DIR__, 3).'/'.$directory;
            $files = new Finder()->files()->in($root)->name('*.php')->contains('/#\[As(Command|Query)Handler\b/');
            foreach ($files as $file) {
                $class = $namespace.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
                if (class_exists($class)) {
                    $classes[] = new ReflectionClass($class);
                }
            }
        }

        return $classes;
    }

    /**
     * @param ReflectionClass<object> $handler
     *
     * @return list<array{AsCommandHandler|AsQueryHandler, string}>
     */
    private static function handlerAttributes(ReflectionClass $handler): array
    {
        $found = [];
        foreach ([AsCommandHandler::class, AsQueryHandler::class] as $type) {
            foreach ($handler->getAttributes($type, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $found[] = [$attribute->newInstance(), '__invoke'];
            }
            foreach ($handler->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes($type, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    $found[] = [$attribute->newInstance(), $method->getName()];
                }
            }
        }

        return $found;
    }

    /**
     * @param ReflectionClass<object> $handler
     *
     * @return class-string
     */
    private static function firstParameterType(ReflectionClass $handler, string $method): string
    {
        $type = $handler->getMethod($method)->getParameters()[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type, sprintf('%s::%s() names its message as one class.', $handler->getName(), $method));
        $name = $type->getName();
        self::assertTrue(class_exists($name), sprintf('%s::%s() takes the class %s.', $handler->getName(), $method, $name));

        return $name;
    }
}
