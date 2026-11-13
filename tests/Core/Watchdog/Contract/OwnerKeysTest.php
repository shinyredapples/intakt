<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Contract;

use Intakt\Core\Watchdog\Contract\OwnerKeys;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OwnerKeys::class)]
final class OwnerKeysTest extends TestCase
{
    public function testAKeyBecomesALineLimitedToGitSignatures(): void
    {
        $keys = OwnerKeys::of(['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample owner@laptop']);

        self::assertSame("owner namespaces=\"git\" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample\n", $keys->allowedSigners());
    }

    public function testNoKeysGiveNoLines(): void
    {
        self::assertSame('', OwnerKeys::of([])->allowedSigners());
    }

    public function testALineWithAnOptionFieldInFrontIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OwnerKeys::of(['cert-authority,namespaces="*" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample']);
    }

    public function testAKeyThatSmugglesASecondLineIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OwnerKeys::of(["ssh-ed25519 AAAA\n* ssh-ed25519 BBBB"]);
    }
}
