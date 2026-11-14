<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\Domain;

use Intakt\Core\Bench\Domain\Expectations;
use Intakt\Core\Bench\Domain\InvalidExpectations;
use Intakt\Core\Bench\Domain\PlantedDefect;
use Intakt\Core\Review\Contract\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Expectations::class)]
#[CoversClass(InvalidExpectations::class)]
#[UsesClass(PlantedDefect::class)]
final class ExpectationsTest extends TestCase
{
    public function testAListedDefectIsReadWithItsFileLinesAndSeverity(): void
    {
        $expectations = Expectations::fromJson('[{"id":"d1","file":"src/a.js","lines":{"from":2,"to":3},"description":"Wrong.","severity":"P1"}]');

        self::assertFalse($expectations->isDefectFree());
        self::assertEquals(new PlantedDefect('d1', 'src/a.js', 2, 3, 'Wrong.', Severity::P1), $expectations->defects[0]);
    }

    public function testAnEmptyListIsADefectFreeChange(): void
    {
        self::assertTrue(Expectations::fromJson('[]')->isDefectFree());
    }

    #[DataProvider('brokenFiles')]
    public function testABrokenFileIsRefusedInWords(string $json, string $reason): void
    {
        $this->expectException(InvalidExpectations::class);
        $this->expectExceptionMessage($reason);

        Expectations::fromJson($json);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brokenFiles(): array
    {
        $valid = '"id":"d1","file":"a.js","description":"Wrong."';

        return [
            'not json' => ['{', 'Syntax error'],
            'not a list' => ['{"id":"d1"}', 'must be a list'],
            'entry not an object' => ['[1]', 'must be an object'],
            'range reversed' => ['[{'.$valid.',"lines":{"from":5,"to":4},"severity":"P1"}]', 'lines of d1'],
            'range starts at zero' => ['[{'.$valid.',"lines":{"from":0,"to":4},"severity":"P1"}]', 'lines of d1'],
            'range missing' => ['[{'.$valid.',"severity":"P1"}]', 'lines of d1'],
            'unknown severity' => ['[{'.$valid.',"lines":{"from":1,"to":1},"severity":"P9"}]', 'severity of d1'],
            'blank file' => ['[{"id":"d1","file":"","description":"x","lines":{"from":1,"to":1},"severity":"P1"}]', '"file"'],
        ];
    }
}
