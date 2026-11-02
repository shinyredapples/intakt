<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Infrastructure;

use Intakt\Core\Mcp\Infrastructure\OwnerTokenHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(OwnerTokenHandler::class)]
final class OwnerTokenHandlerTest extends TestCase
{
    public function testTheConfiguredTokenIdentifiesTheOwner(): void
    {
        $token = bin2hex(random_bytes(8));

        $badge = new OwnerTokenHandler($token)->getUserBadgeFrom($token);

        self::assertSame('mcp-owner', $badge->getUserIdentifier());
        $loader = $badge->getUserLoader();
        self::assertIsCallable($loader);
        $user = $loader('mcp-owner');
        self::assertInstanceOf(UserInterface::class, $user);
        self::assertContains(OwnerTokenHandler::OWNER_ROLE, $user->getRoles());
    }

    public function testAnotherTokenIsRefused(): void
    {
        $this->expectException(BadCredentialsException::class);

        new OwnerTokenHandler(bin2hex(random_bytes(8)))->getUserBadgeFrom(bin2hex(random_bytes(8)));
    }

    public function testNoTokenIsAcceptedWhileNoneIsConfigured(): void
    {
        $this->expectException(BadCredentialsException::class);

        new OwnerTokenHandler('')->getUserBadgeFrom('');
    }
}
