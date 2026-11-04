<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform;

use Intakt\Tests\Support\KernelTestCase;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Core\Platform\Contract\Bus;
use Intakt\Tests\Core\Platform\Handler\FindThing;
use Intakt\Tests\Core\Platform\Handler\FindThingHandler;
use Intakt\Tests\Core\Platform\Handler\Ping;
use Intakt\Tests\Core\Platform\Handler\PingHandler;
use Intakt\Tests\Core\Platform\Handler\RenameThing;
use Intakt\Tests\Core\Platform\Handler\RenameThingHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(AsCommandHandler::class)]
#[CoversClass(AsQueryHandler::class)]
#[UsesClass(Bus::class)]
#[UsesClass(RenameThing::class)]
#[UsesClass(RenameThingHandler::class)]
#[UsesClass(FindThing::class)]
#[UsesClass(FindThingHandler::class)]
#[UsesClass(Ping::class)]
#[UsesClass(PingHandler::class)]
final class BusSeparationTest extends KernelTestCase
{
    use HandleTrait;

    public function testACommandHandlerRunsOnTheCommandBus(): void
    {
        $this->messageBus = $this->bus('command.bus');

        self::assertSame('ABC', $this->handle(new RenameThing('abc')));
    }

    public function testAQueryHandlerRunsOnTheQueryBus(): void
    {
        $this->messageBus = $this->bus('query.bus');

        self::assertSame('thing', $this->handle(new FindThing()));
    }

    public function testACommandHandlerIsNotCallableOnTheQueryBus(): void
    {
        $this->messageBus = $this->bus('query.bus');

        $this->expectException(NoHandlerForMessageException::class);

        $this->handle(new RenameThing('abc'));
    }

    public function testAQueryHandlerIsNotCallableOnTheCommandBus(): void
    {
        $this->messageBus = $this->bus('command.bus');

        $this->expectException(NoHandlerForMessageException::class);

        $this->handle(new FindThing());
    }

    public function testAHandlerThatIgnoresItsMessageIsRoutedByItsHandlesDeclaration(): void
    {
        $this->messageBus = $this->bus('command.bus');

        self::assertSame('pong', $this->handle(new Ping()));
    }

    private function bus(string $id): MessageBusInterface
    {
        $bus = self::getContainer()->get($id);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }
}
