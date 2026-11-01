<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow\Infrastructure;

use Intakt\Core\Shadow\Infrastructure\ShadowGuardedForgeWrites;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Tests\Core\Shadow\WriteRefusingForge;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShadowGuardedForgeWrites::class)]
#[UsesClass(ChangeId::class)]
final class ShadowGuardedForgeWritesTest extends TestCase
{
    public function testAShadowProjectsReviewNeverReachesTheForge(): void
    {
        $forge = new WriteRefusingForge();
        $guard = new ShadowGuardedForgeWrites($forge, ['alpha' => 'shadow']);

        $guard->postReview('alpha', new ChangeId('feature'), 'approve');

        self::assertSame([], $forge->writeLog);
    }

    public function testAProjectWithoutAModeWritesToTheForge(): void
    {
        $forge = new WriteRefusingForge();
        $guard = new ShadowGuardedForgeWrites($forge, ['alpha' => 'shadow']);

        $this->expectException(LogicException::class);

        $guard->postReview('beta', new ChangeId('feature'), 'approve');
    }

    public function testALiveProjectWritesToTheForge(): void
    {
        $forge = new WriteRefusingForge();
        $guard = new ShadowGuardedForgeWrites($forge, ['alpha' => 'live']);

        $this->expectExceptionObject(new LogicException('The test forge refuses every write.'));

        $guard->postReview('alpha', new ChangeId('feature'), 'approve');
    }
}
