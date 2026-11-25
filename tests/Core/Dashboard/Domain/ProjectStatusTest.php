<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\ProjectStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectStatus::class)]
final class ProjectStatusTest extends TestCase
{
    public function testEachStatusHasItsWordAndItsDot(): void
    {
        self::assertSame(
            [['Failing', 'broken'], ['Running', 'ok'], ['Quiet', 'idle']],
            array_map(static fn (ProjectStatus $status): array => [$status->words(), $status->dot()], ProjectStatus::cases()),
        );
    }
}
