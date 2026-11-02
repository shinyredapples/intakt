<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ServeReviewTools;

use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(ReviewCredential::class)]
final class ReviewCredentialTest extends TestCase
{
    private const string ROUND = '0196f5a2-6c3e-7a10-9b2d-3f4e5a6b7c8d';

    public function testAnIssuedCredentialNamesItsRoundAsAReviewer(): void
    {
        $credential = new ReviewCredential('secret');

        $badge = $credential->getUserBadgeFrom($credential->issueFor(self::ROUND));

        self::assertSame(self::ROUND, $badge->getUserIdentifier());
        $loader = $badge->getUserLoader();
        self::assertIsCallable($loader);
        $user = $loader(self::ROUND);
        self::assertInstanceOf(UserInterface::class, $user);
        self::assertSame([ReviewCredential::REVIEWER_ROLE], $user->getRoles());
    }

    public function testACredentialSignedWithAnotherSecretIsRefused(): void
    {
        $this->expectException(BadCredentialsException::class);

        new ReviewCredential('secret')->getUserBadgeFrom(new ReviewCredential('another secret')->issueFor(self::ROUND));
    }

    public function testARoundWithAnotherRoundsSignatureIsRefused(): void
    {
        $credential = new ReviewCredential('secret');
        $signature = explode('.', $credential->issueFor(self::ROUND))[1];

        $this->expectException(BadCredentialsException::class);

        $credential->getUserBadgeFrom('0196f5a2-6c3e-7a10-9b2d-3f4e5a6b7c8e.'.$signature);
    }

    public function testATokenThatIsNoRoundIsRefused(): void
    {
        $credential = new ReviewCredential('secret');

        foreach (['', self::ROUND, 'not-a-round.'.explode('.', $credential->issueFor(self::ROUND))[1]] as $token) {
            try {
                $credential->getUserBadgeFrom($token);
                self::fail(sprintf('"%s" was accepted.', $token));
            } catch (BadCredentialsException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
