<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Infrastructure;

use Intakt\Core\Platform\Infrastructure\HeldCredentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(HeldCredentials::class)]
final class HeldCredentialsTest extends TestCase
{
    private const string NAME = 'INTAKT_TEST_API_KEY';

    private string $vaultDirectory;

    protected function setUp(): void
    {
        $this->vaultDirectory = sys_get_temp_dir().'/intakt-held-credentials-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        unset($_SERVER[self::NAME], $_SERVER[self::NAME.'_TEAM'], $_SERVER['OTHER_'.self::NAME], $_SERVER[self::NAME.'S']);
        new Filesystem()->remove($this->vaultDirectory);
    }

    public function testAVariableItNamesIsHeld(): void
    {
        $_SERVER[self::NAME] = 'default-key';

        self::assertSame(['default-key'], new HeldCredentials([self::NAME])->values());
    }

    public function testAnAccountVariableWithItsNameAppendedIsHeld(): void
    {
        $_SERVER[self::NAME.'_TEAM'] = 'team-key-0123456789abcdef';

        self::assertSame(['team-key-0123456789abcdef'], new HeldCredentials([self::NAME])->values());
    }

    public function testAVariableThatOnlySharesPartOfTheNameIsNotHeld(): void
    {
        $_SERVER['OTHER_'.self::NAME] = 'prefixed';
        $_SERVER[self::NAME.'S'] = 'longer';

        self::assertSame([], new HeldCredentials([self::NAME])->values());
    }

    public function testAnEmptyVariableIsNotHeld(): void
    {
        $_SERVER[self::NAME] = '';

        self::assertSame([], new HeldCredentials([self::NAME])->values());
    }

    public function testASecretInTheVaultIsHeld(): void
    {
        $vault = $this->vaultHolding([self::NAME => 'vault-credential-canary', self::NAME.'_TEAM' => 'vault-team-canary']);

        self::assertSame(['vault-credential-canary', 'vault-team-canary'], new HeldCredentials([self::NAME], $vault)->values());
    }

    public function testASecretInTheVaultThatIsNoCredentialIsNotHeld(): void
    {
        $vault = $this->vaultHolding(['INTAKT_TEST_PERMISSIONS' => 'contents:read']);

        self::assertSame([], new HeldCredentials([self::NAME], $vault)->values());
    }

    public function testAVaultThatCannotBeDecryptedHoldsNothingInsteadOfFailing(): void
    {
        $this->vaultHolding([self::NAME => 'vault-credential-canary']);

        self::assertSame([], new HeldCredentials([self::NAME], new SodiumVault($this->vaultDirectory, 'too-short'))->values());
    }

    /**
     * @param array<string, string> $secrets
     */
    private function vaultHolding(array $secrets): SodiumVault
    {
        $vault = new SodiumVault($this->vaultDirectory);
        $vault->generateKeys();
        foreach ($secrets as $name => $value) {
            $vault->seal($name, $value);
        }

        return $vault;
    }
}
