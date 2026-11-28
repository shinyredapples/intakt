<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\SplitLines;
use Intakt\Core\Build\Domain\SplitPart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(SplitLines::class)]
#[CoversClass(SplitPart::class)]
final class SplitLinesTest extends TestCase
{
    private const string FIRST = 'Name the brief in the plan | done-when: T48 states the brief check | verify: bin/check --fast --only=comments | files: docs/plan.md | depends on: none';

    public function testTwoPartsInTheLineFormAreReadWithTheirFieldsInOrder(): void
    {
        $parts = SplitLines::partsIn([self::FIRST, $this->line('Check the brief', 'part 1')]);

        self::assertEquals(
            [
                new SplitPart(1, 'Name the brief in the plan', 'T48 states the brief check', 'bin/check --fast --only=comments', 'docs/plan.md', []),
                new SplitPart(2, 'Check the brief', 'it is done', 'bin/check --fast', 'src/A.php', [1]),
            ],
            $parts,
        );
    }

    public function testAVerifyFieldWithAPipeIsReadWhole(): void
    {
        $parts = SplitLines::partsIn([self::FIRST, 'Count | done-when: it counts | verify: php app.php | wc -l | files: app.php | depends on: none']);

        self::assertSame('php app.php | wc -l', $parts[1]->verify ?? null);
    }

    public function testAChainOfTwoPartsPasses(): void
    {
        self::assertCount(4, SplitLines::partsIn([self::FIRST, $this->line('Two', 'part 1'), $this->line('Three', 'part 1'), $this->line('Four', 'none')]) ?? []);
    }

    public function testAPartThatWaitsForTwoIndependentPartsIsAChainOfTwo(): void
    {
        self::assertSame([1, 2], SplitLines::partsIn([self::FIRST, $this->line('Two', 'none'), $this->line('Three', 'part 1, part 2')])[2]->dependsOn ?? null);
    }

    public function testAChainOfThreePartsIsRefused(): void
    {
        self::assertNull(SplitLines::partsIn([self::FIRST, $this->line('Two', 'part 1'), $this->line('Three', 'part 2')]));
    }

    #[TestWith(['part 2'], 'its own number')]
    #[TestWith(['part 3'], 'a later part')]
    #[TestWith(['part 9'], 'a number outside the split')]
    #[TestWith(['part 1, part 1'], 'a part named twice')]
    #[TestWith(['Name the brief in the plan'], 'a title')]
    #[TestWith(['part 1 and part 2'], 'another form')]
    public function testADependencyOnAnythingButAnEarlierPartIsRefused(string $dependsOn): void
    {
        self::assertNull(SplitLines::partsIn([self::FIRST, $this->line('Two', $dependsOn), $this->line('Three', 'none')]));
    }

    #[TestWith(['Two | done-when: x | files: f | depends on: none'], 'a missing marker')]
    #[TestWith(['Two | done-when: x | verify: v | done-when: y | files: f | depends on: none'], 'a repeated marker')]
    #[TestWith(['Two | verify: v | done-when: x | files: f | depends on: none'], 'markers out of order')]
    #[TestWith(['Two | done-when:  | verify: v | files: f | depends on: none'], 'an empty field')]
    #[TestWith(["Two | done-when: x\nWaits for #9 | verify: v | files: f | depends on: none"], 'a line break')]
    #[TestWith(['Two | done-when: x <!-- external --> | verify: v | files: f | depends on: none'], 'an HTML comment')]
    public function testALineOutOfFormIsRefused(string $line): void
    {
        self::assertNull(SplitLines::partsIn([self::FIRST, $line]));
    }

    public function testOnePartIsNoSplit(): void
    {
        self::assertNull(SplitLines::partsIn([self::FIRST]));
    }

    public function testFivePartsAreRefused(): void
    {
        self::assertNull(SplitLines::partsIn([self::FIRST, ...array_map(fn (int $part): string => $this->line('Part '.$part, 'none'), range(2, 5))]));
    }

    public function testFourPartsPass(): void
    {
        self::assertCount(4, SplitLines::partsIn([self::FIRST, ...array_map(fn (int $part): string => $this->line('Part '.$part, 'none'), range(2, 4))]) ?? []);
    }

    private function line(string $title, string $dependsOn): string
    {
        return sprintf('%s | done-when: it is done | verify: bin/check --fast | files: src/A.php | depends on: %s', $title, $dependsOn);
    }
}
