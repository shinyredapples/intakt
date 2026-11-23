<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Finding;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;

// Covers nothing because the subject is Symfony AI's schema of a class; it decides that Finding::$fixable_by keeps its name.
#[CoversNothing]
final class FindingSchemaTest extends TestCase
{
    public function testTheStructuredOutputSchemaOfAFindingNamesTheFieldFixableBy(): void
    {
        /** @var array<string, mixed> $properties */
        $properties = new ResponseFormatFactory()->create(Finding::class)['json_schema']['schema']['properties'];

        self::assertArrayHasKey('fixable_by', $properties);
        self::assertArrayNotHasKey('fixableBy', $properties);
    }

    public function testTheStructuredOutputSchemaOfAFindingAsksForTheGateGapMarkerAsABoolean(): void
    {
        /** @var array<string, array<string, mixed>> $properties */
        $properties = new ResponseFormatFactory()->create(Finding::class)['json_schema']['schema']['properties'];

        self::assertSame(['type' => 'boolean'], $properties['gate_gap']);
    }

    public function testTheStructuredOutputSchemaOfAFindingDoesNotAskForMergedIds(): void
    {
        /** @var array<string, mixed> $properties */
        $properties = new ResponseFormatFactory()->create(Finding::class)['json_schema']['schema']['properties'];

        self::assertArrayNotHasKey('merged', $properties);
    }

    public function testTheStructuredOutputSchemaOfAFindingDoesNotAskForAJudgeNote(): void
    {
        /** @var array<string, mixed> $properties */
        $properties = new ResponseFormatFactory()->create(Finding::class)['json_schema']['schema']['properties'];

        self::assertArrayNotHasKey('judge_note', $properties);
    }
}
