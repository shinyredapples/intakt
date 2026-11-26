<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Contract;

use Intakt\Core\Budget\Contract\UnpricedModels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnpricedModels::class)]
final class UnpricedModelsTest extends TestCase
{
    public function testUnpricedModelsKeepTheFirstSeenOrderAndDropRepeats(): void
    {
        self::assertSame(['local-small', 'gpt-5'], [...new UnpricedModels('local-small', 'gpt-5', 'local-small', 'gpt-5')]);
    }

    public function testNoModelsAreEmpty(): void
    {
        self::assertSame([true, false], [new UnpricedModels()->isEmpty(), new UnpricedModels('gpt-5')->isEmpty()]);
    }
}
