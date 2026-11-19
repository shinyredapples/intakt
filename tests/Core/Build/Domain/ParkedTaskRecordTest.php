<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use DateTimeImmutable;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParkedTaskRecord::class)]
#[UsesClass(BuildQuestion::class)]
final class ParkedTaskRecordTest extends TestCase
{
    public function testAParkedTaskRecordKeepsTheQuestionExactlyAsChecked(): void
    {
        $question = BuildQuestion::fromHandedBack('{"question":"  Which store?  ","choices":[" SQLite ","Postgres"],"recommendation":"SQLite","extra":"x"}');
        $parkedAt = new DateTimeImmutable('2026-11-19T10:00:00+00:00');

        $row = new ParkedTaskRecord('alpha', 7, $question, $parkedAt)->row();

        self::assertSame(['project' => 'alpha', 'task' => 7, 'question' => 'Which store?', 'choices' => ['SQLite', 'Postgres'], 'recommendation' => 'SQLite', 'parked_at' => $parkedAt], $row);
    }

    public function testTheRowNamesTheTypesOfTheColumnsThatAreNoText(): void
    {
        self::assertSame(['task' => 'integer', 'choices' => 'json', 'parked_at' => 'utc_datetime_immutable'], ParkedTaskRecord::rowTypes());
    }
}
