<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DesignerPlanTaskIdsTest extends TestCase
{
    public function testEachDesignerTaskIdNamesNoOtherTaskInThePlan(): void
    {
        $titlesById = [];
        foreach ($this->headings() as [, $id, $title]) {
            $titlesById[$id][] = $title;
        }

        $designerIds = [];
        foreach ($this->headings() as [, $id, $title]) {
            if (str_contains($title, 'Designer')) {
                $designerIds[] = $id;
                self::assertSame([$title], $titlesById[$id], $id.' names more than one task.');
            }
        }

        self::assertNotEmpty($designerIds);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function headings(): array
    {
        $plan = (string) file_get_contents(dirname(__DIR__, 2).'/docs/plan.md');
        preg_match_all('/^### (T\d+) (.+)$/m', $plan, $headings, PREG_SET_ORDER);

        return $headings;
    }
}
