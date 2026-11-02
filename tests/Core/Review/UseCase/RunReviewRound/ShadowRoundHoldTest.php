<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\ChangeDecision;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ShadowCeilingReached;
use Intakt\Core\Review\UseCase\RunReviewRound\ShadowRoundHold;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Core\Shadow\Infrastructure\ConfiguredProjectModes;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\WorkerOnSleep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Serializer\SerializerInterface;

#[Group('persistence')]
#[CoversClass(ShadowRoundHold::class)]
#[CoversClass(ShadowCeilingReached::class)]
#[UsesClass(ReviewChange::class)]
#[UsesClass(Account::class)]
final class ShadowRoundHoldTest extends KernelTestCase
{
    public function testAShadowRoundAboveTheShadowCeilingDoesNotStart(): void
    {
        $fixture = $this->fixtureOf('shadow');

        try {
            $fixture->startHandlerOn($fixture->bus, $this->everyAccountAt(StartDecision::AtCeiling))($fixture->commandFor(RoundFixture::CHANGE, 2));
            self::fail('The round above the shadow ceiling started.');
        } catch (ShadowCeilingReached) {
            self::assertSame([], $fixture->storedRounds());
            self::assertSame([], $fixture->bus->messages);
        }
    }

    public function testTheReasonOfAHeldShadowRoundIsRecordedInTheLedger(): void
    {
        $fixture = $this->fixtureOf('shadow');

        try {
            $fixture->startHandlerOn($fixture->bus, $this->everyAccountAt(StartDecision::AtCeiling))($fixture->commandFor(RoundFixture::CHANGE, 2));
        } catch (ShadowCeilingReached) {
        }

        self::assertSame([LedgerEventKind::ShadowRoundHeld], $fixture->ledgerKinds());
        self::assertSame(['project' => 'alpha', 'change' => RoundFixture::CHANGE, 'provider' => 'anthropic', 'reason' => 'at-ceiling'], $this->heldPayload($fixture));
    }

    public function testAShadowRoundBelowTheShadowCeilingStarts(): void
    {
        $fixture = $this->fixtureOf('shadow');

        $id = $fixture->startHandlerOn($fixture->bus, new ScriptedShadowGuard())($fixture->commandFor(RoundFixture::CHANGE, 2));

        self::assertSame($id, $fixture->round($id)->id()->toRfc4122());
        self::assertSame([LedgerEventKind::ReviewStarted], $fixture->ledgerKinds());
    }

    public function testAShadowRoundStartsWhileAnotherAccountOfTheProviderIsBelowTheCeiling(): void
    {
        $fixture = $this->fixtureOf('shadow');
        $guard = new ScriptedShadowGuard(['anthropic.default' => StartDecision::AtCeiling]);

        $id = $fixture->startHandlerOn($fixture->bus, $guard)($fixture->commandFor(RoundFixture::CHANGE, 2));

        self::assertSame(['anthropic.default', 'anthropic.team'], $guard->asked);
        self::assertSame($id, $fixture->round($id)->id()->toRfc4122());
    }

    public function testALiveRoundStartsAboveTheShadowCeilingWithoutAskingIt(): void
    {
        $fixture = $this->fixtureOf('live');
        $guard = $this->everyAccountAt(StartDecision::AtCeiling);

        $id = $fixture->startHandlerOn($fixture->bus, $guard)($fixture->commandFor(RoundFixture::CHANGE, 2));

        self::assertSame([], $guard->asked);
        self::assertSame($id, $fixture->round($id)->id()->toRfc4122());
    }

    public function testTheCallerOfAHeldRoundGetsTheHoldAndNotAWrappedHandlerFailure(): void
    {
        $fixture = $this->fixtureOf('shadow');
        $handler = $fixture->startHandlerOn($fixture->bus, $this->everyAccountAt(StartDecision::AtCeiling));
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RunReviewRound::class => [$handler]]))]);
        $command = $fixture->commandFor(RoundFixture::CHANGE, 2);
        $request = new ChangeReviewRequest('alpha', __DIR__, RoundFixture::TASK, RoundFixture::DIFF, new RequestedRound(RoundFixture::CHANGE), SampleIdentity::of(RoundFixture::TASK, $command->panel), $command->panel);

        $this->expectException(ShadowCeilingReached::class);

        $this->reviewChange($fixture, $bus)->start($request);
    }

    private function fixtureOf(string $mode): RoundFixture
    {
        return new RoundFixture(self::getContainer(), new ConfiguredProjectModes(['alpha' => $mode]));
    }

    private function everyAccountAt(StartDecision $decision): ScriptedShadowGuard
    {
        return new ScriptedShadowGuard(['anthropic.default' => $decision, 'anthropic.team' => $decision]);
    }

    private function reviewChange(RoundFixture $fixture, MessageBus $bus): ReviewChange
    {
        $serializer = self::getContainer()->get(SerializerInterface::class);
        assert($serializer instanceof SerializerInterface);

        return new ReviewChange(
            $bus,
            $fixture->transactions(),
            new StoredFindings($fixture->entities, $serializer, $fixture->machine('state_machine.review_round')),
            new RoundLedgerReading($fixture->ledger()),
            new ChangeDecision(),
            new StoredVerdicts($serializer),
            new WorkerOnSleep($fixture->clock, static function (): void {
            }),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function heldPayload(RoundFixture $fixture): array
    {
        $held = array_values(array_filter(iterator_to_array($fixture->ledger()->readInOrder()), static fn (RecordedEvent $event): bool => LedgerEventKind::ShadowRoundHeld === $event->event->kind));
        $payload = json_decode($held[0]->event->payload, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($payload));

        return array_combine(array_map(strval(...), array_keys($payload)), $payload);
    }
}
