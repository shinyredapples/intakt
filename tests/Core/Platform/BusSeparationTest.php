<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Tests\Core\Platform\Handler\FindThing;
use Intakt\Tests\Core\Platform\Handler\Ping;
use Intakt\Tests\Core\Platform\Handler\RenameThing;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(AsCommandHandler::class)]
#[CoversClass(AsQueryHandler::class)]
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
