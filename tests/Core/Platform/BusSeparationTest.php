<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Core\Platform\Contract\Bus;
use Intakt\Core\Platform\Contract\CommandMessage;
use Intakt\Core\Platform\Contract\QueryMessage;
use Intakt\Tests\Core\Platform\Handler\FindThing;
use Intakt\Tests\Core\Platform\Handler\FindThingHandler;
use Intakt\Tests\Core\Platform\Handler\Ping;
use Intakt\Tests\Core\Platform\Handler\PingHandler;
use Intakt\Tests\Core\Platform\Handler\RenameThing;
use Intakt\Tests\Core\Platform\Handler\RenameThingHandler;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use stdClass;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

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

    public function testACommandSentOnTheQueryBusIsRefused(): void
    {
        $this->messageBus = $this->bus('query.bus');

        $this->expectExceptionObject(new InvalidArgumentException(sprintf(
            'This bus only dispatches messages of type "%s", "%s" given.',
            QueryMessage::class,
            RenameThing::class,
        )));

        $this->handle(new RenameThing('abc'));
    }

    public function testAQuerySentOnTheCommandBusIsRefused(): void
    {
        $this->messageBus = $this->bus('command.bus');

        $this->expectExceptionObject(new InvalidArgumentException(sprintf(
            'This bus only dispatches messages of type "%s", "%s" given.',
            CommandMessage::class,
            FindThing::class,
        )));

        $this->handle(new FindThing());
    }

    #[TestWith(['command.bus', CommandMessage::class])]
    #[TestWith(['query.bus', QueryMessage::class])]
    public function testAMessageWithNoBusMarkerIsRefusedOnEitherBus(string $bus, string $marker): void
    {
        $this->messageBus = $this->bus($bus);

        $this->expectExceptionObject(new InvalidArgumentException(sprintf(
            'This bus only dispatches messages of type "%s", "%s" given.',
            $marker,
            stdClass::class,
        )));

        $this->handle(new stdClass());
    }

    public function testAMessageReceivedFromATransportIsNotRefusedByTheBus(): void
    {
        $this->expectException(NoHandlerForMessageException::class);

        $this->bus('command.bus')->dispatch(new FindThing(), [new ReceivedStamp('async')]);
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
