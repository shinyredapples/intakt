<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\StateMarkingStore;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(StateMarkingStore::class)]
final class StateMarkingStoreTest extends TestCase
{
    public function testTheMarkingStoreRefusesASubjectThatKeepsNoPlace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StateMarkingStore()->getMarking(new stdClass());
    }
}
