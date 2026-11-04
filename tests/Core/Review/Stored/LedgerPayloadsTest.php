<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Stored;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\AccountCooledDown;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\UseCase\RunReviewRound\Payload\CatchUpOwed;
use Intakt\Core\Review\UseCase\RunReviewRound\Payload\JudgeDone;
use Intakt\Core\Review\UseCase\RunReviewRound\Payload\ReviewerBlocked;
use Intakt\Core\Review\UseCase\RunReviewRound\Payload\ReviewerFailed;
use Intakt\Core\Review\UseCase\RunReviewRound\Payload\ReviewerStarted;
use Intakt\Core\Review\UseCase\RunReviewRound\Payload\ReviewerStopped;
use Intakt\Core\Review\UseCase\RunReviewRound\Payload\ReviewStarted;
use Intakt\Core\Review\UseCase\RunReviewRound\Payload\ShadowRoundHeld;
use Intakt\Core\Review\UseCase\RunReviewRound\Payload\VerdictReceived;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageUnsettled;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

// Covers nothing because the subject is the configured serializer reading and writing every ledger payload class, with rows as they are stored today.
#[CoversNothing]
final class LedgerPayloadsTest extends KernelTestCase
{
    private const string IDENTITY = '"round":"r","reviewer":"v","role":"correctness","tier":"standard"';

    private const string USAGE = '{"account":null,"model":"m","prompt":10,"completion":2,"thinking":null,"tool":null,"cached":null,"cache_creation":3,"cache_read":4,"total":12,"remaining":null,"remaining_minute":7,"remaining_month":null}';

    /**
     * @param class-string $class
     */
    #[DataProvider('rowsInTodaysFormat')]
    public function testARowInTodaysFormatReadsIntoItsPayloadAndWritesTheSameJson(string $class, string $row): void
    {
        $payload = $this->serializer()->deserialize($row, $class, 'json');

        self::assertInstanceOf($class, $payload);
        self::assertSame($row, $this->serializer()->serialize($payload, 'json', [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]));
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function rowsInTodaysFormat(): iterable
    {
        $identity = self::IDENTITY;

        yield 'an account cooldown' => [AccountCooledDown::class, '{"account":"anthropic:team","until":"2026-11-04T12:00:00+00:00"}'];
        yield 'a started usage with a round' => [UsageStarted::class, '{"call":"c","phase":"started","project":"p","tier":"standard","round":"r"}'];
        yield 'a started usage without a round' => [UsageStarted::class, '{"call":"c","phase":"started","project":"p","tier":"standard"}'];
        yield 'a review started with a source' => [ReviewStarted::class, '{"round":"r","project":"p","reviewers":2,"change":"c","number":1,"task":"t","repository":"o\\/r","base":"main"}'];
        yield 'a review started without a source' => [ReviewStarted::class, '{"round":"r","project":"p","reviewers":2,"change":"c","number":1,"task":"t"}'];
        yield 'a held shadow round' => [ShadowRoundHeld::class, '{"project":"p","change":"c","provider":"anthropic","reason":"at-ceiling"}'];
        yield 'a reviewer started' => [ReviewerStarted::class, "{{$identity}}"];
        yield 'a verdict received' => [VerdictReceived::class, "{{$identity},\"decision\":\"changes\",\"findings\":2,\"mode\":\"live\"}"];
        yield 'a catch-up owed' => [CatchUpOwed::class, "{{$identity},\"substitute\":\"openai\"}"];
        yield 'a reviewer failed' => [ReviewerFailed::class, "{{$identity},\"failure\":\"timeout\"}"];
        yield 'a reviewer blocked' => [ReviewerBlocked::class, "{{$identity},\"reason\":\"no tools\"}"];
        yield 'a reviewer stopped' => [ReviewerStopped::class, "{{$identity}}"];
        yield 'a judge done' => [JudgeDone::class, '{"round":"r","act":["a"],"follow_ups":["b"],"findings":[{"id":"a","severity":"P2","text":"Broken."}]}'];
    }

    public function testASettledUsageRowKeepsItsNullTokenFieldsAndReadsBackEqual(): void
    {
        $row = '{"call":"c","phase":"settled","usages":['.self::USAGE.']}';

        $payload = $this->serializer()->deserialize($row, UsageSettled::class, 'json');

        self::assertSame($row, $this->serializer()->serialize($payload, 'json'));
        self::assertNull($payload->usages[0]->remaining);
        self::assertSame(7, $payload->usages[0]->remainingMinute);
    }

    public function testAnUnsettledUsageRowKeepsItsFailureClassAndItsUsages(): void
    {
        $row = '{"call":"c","phase":"unsettled","failure":"RuntimeException","usages":['.self::USAGE.']}';

        $payload = $this->serializer()->deserialize($row, UsageUnsettled::class, 'json');

        self::assertSame($row, $this->serializer()->serialize($payload, 'json'));
        self::assertSame('RuntimeException', $payload->failure);
    }

    public function testTheClosedSetsOfARoundRowReadIntoTheirEnumsAndWriteBackTheirStoredValues(): void
    {
        $verdict = '{'.self::IDENTITY.',"decision":"changes","findings":2,"mode":"shadow"}';
        $catchUp = '{'.self::IDENTITY.',"substitute":"openai_compatible"}';

        $received = $this->serializer()->deserialize($verdict, VerdictReceived::class, 'json');
        $owed = $this->serializer()->deserialize($catchUp, CatchUpOwed::class, 'json');

        self::assertSame([Tier::Standard, Decision::Changes, ProjectMode::Shadow], [$received->tier, $received->decision, $received->mode]);
        self::assertSame(Provider::OpenAiCompatible, $owed->substitute);
        self::assertSame($verdict, $this->serializer()->serialize($received, 'json'));
        self::assertSame($catchUp, $this->serializer()->serialize($owed, 'json'));
    }

    public function testTheClosedSetsOfUsageShadowAndJudgeRowsReadIntoTheirEnums(): void
    {
        $started = $this->serializer()->deserialize('{"call":"c","phase":"started","project":"p","tier":"light"}', UsageStarted::class, 'json');
        $held = $this->serializer()->deserialize('{"project":"p","change":"c","provider":"claude_code","reason":"no-trusted-reading"}', ShadowRoundHeld::class, 'json');
        $judged = $this->serializer()->deserialize('{"round":"r","act":[],"follow_ups":[],"findings":[{"id":"a","severity":"P1","text":"Broken."}]}', JudgeDone::class, 'json');

        self::assertSame(Tier::Light, $started->tier);
        self::assertSame([Provider::ClaudeCode, StartDecision::NoTrustedReading], [$held->provider, $held->reason]);
        self::assertSame(Severity::P1, $judged->findings[0]->severity);
    }

    public function testAHeldShadowRoundWritesItsReasonAsTheStoredString(): void
    {
        $held = new ShadowRoundHeld('p', 'c', Provider::Anthropic, StartDecision::ReadingNotFreshEnough);

        self::assertSame(
            '{"project":"p","change":"c","provider":"anthropic","reason":"reading-not-fresh-enough"}',
            $this->serializer()->serialize($held, 'json'),
        );
    }

    public function testTheHandBuiltStoredSerializerWritesAPayloadAsTheContainersDoes(): void
    {
        $payload = new VerdictReceived('r', 'v', 'correctness', Tier::Strong, Decision::Approve, 0, ProjectMode::Live);

        self::assertSame(
            $this->serializer()->serialize($payload, 'json'),
            StoredSerializerFactory::create()->serialize($payload, 'json'),
        );
    }

    private function serializer(): SerializerInterface
    {
        $serializer = self::getContainer()->get('serializer.stored');
        assert($serializer instanceof SerializerInterface);

        return $serializer;
    }
}
