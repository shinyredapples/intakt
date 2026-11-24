<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Domain\LabelFamilies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LabelFamilies::class)]
#[UsesClass(LabelApplication::class)]
final class LabelFamiliesTest extends TestCase
{
    public function testTwoValuesOfOneFamilyResolveToTheOneAppliedLast(): void
    {
        $applied = $this->applied('model:strong', 'model:light');

        self::assertSame(['model:light'], $this->names(new LabelFamilies()->winners($applied)));
    }

    public function testTheLoserIsNamedForRemovalWhateverItsPlaceInTheList(): void
    {
        $applied = $this->applied('priority:low', 'model:strong', 'priority:high');

        self::assertSame(['priority:low'], new LabelFamilies()->losers($applied));
        self::assertSame(['model:strong', 'priority:high'], $this->names(new LabelFamilies()->winners($applied)));
    }

    public function testSeveralReviewValuesAreAllKept(): void
    {
        $applied = $this->applied('review:full', 'review:security');

        self::assertSame(['review:full', 'review:security'], $this->names(new LabelFamilies()->winners($applied)));
        self::assertSame([], new LabelFamilies()->losers($applied));
    }

    public function testALabelWithoutAFamilyIsNeverRemoved(): void
    {
        $applied = $this->applied('hands-off', 'take-over', 'keep');

        self::assertSame([], new LabelFamilies()->losers($applied));
    }

    /**
     * @return list<LabelApplication>
     */
    private function applied(string ...$labels): array
    {
        return array_map(static fn (string $label): LabelApplication => new LabelApplication($label, RecordedTrust::WRITER), array_values($labels));
    }

    /**
     * @param list<LabelApplication> $applied
     *
     * @return list<string>
     */
    private function names(array $applied): array
    {
        return array_map(static fn (LabelApplication $application): string => $application->label, $applied);
    }
}
