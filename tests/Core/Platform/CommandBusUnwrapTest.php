<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Core\Platform\Contract\Bus;
use Intakt\Tests\Core\Platform\Handler\FindRefusedThing;
use Intakt\Tests\Core\Platform\Handler\FindRefusedThingHandler;
use Intakt\Tests\Core\Platform\Handler\RefuseThing;
use Intakt\Tests\Core\Platform\Handler\RefuseThingHandler;
use Intakt\Tests\Core\Platform\Handler\RelayRefusal;
use Intakt\Tests\Core\Platform\Handler\RelayRefusalHandler;
use Intakt\Tests\Core\Platform\Handler\ThingRefused;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

#[CoversClass(AsCommandHandler::class)]
#[CoversClass(AsQueryHandler::class)]
#[UsesClass(Bus::class)]
#[UsesClass(FindRefusedThing::class)]
#[UsesClass(FindRefusedThingHandler::class)]
#[UsesClass(RefuseThing::class)]
#[UsesClass(RefuseThingHandler::class)]
#[UsesClass(RelayRefusal::class)]
#[UsesClass(RelayRefusalHandler::class)]
#[UsesClass(ThingRefused::class)]
final class CommandBusUnwrapTest extends KernelTestCase
{
    public function testAHandlersReportableFailureReachesTheCallerOfTheCommandBusAsItself(): void
    {
        $refusal = new ThingRefused('Refused.');

        self::assertSame($refusal, $this->failureOf(Bus::Command, new RefuseThing($refusal)));
    }

    public function testAFailureOfACommandDispatchedInsideAHandlerReachesTheOuterCallerAsItself(): void
    {
        $refusal = new ThingRefused('Refused one level down.');

        self::assertSame($refusal, $this->failureOf(Bus::Command, new RelayRefusal($refusal)));
    }

    public function testAHandlersFailureOnTheQueryBusStillArrivesWrapped(): void
    {
        $failure = $this->failureOf(Bus::Query, new FindRefusedThing(new ThingRefused('Refused.')));

        self::assertInstanceOf(HandlerFailedException::class, $failure);
    }

    private function failureOf(Bus $bus, object $message): Throwable
    {
        $messageBus = self::getContainer()->get($bus->value);
        self::assertInstanceOf(MessageBusInterface::class, $messageBus);

        try {
            $messageBus->dispatch($message);
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The handler did not fail.');
    }
}
