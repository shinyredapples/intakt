<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Infrastructure;

use Intakt\Core\Ai\Contract\KeptProviderKeys;
use Intakt\Core\Ai\Contract\KeptProviderKeyUnreadable;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Platform\Infrastructure\HeldCredentials;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnthropicDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\HuggingFaceDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\OpenAiDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;
use UnexpectedValueException;

#[CoversClass(HeldCredentials::class)]
#[UsesClass(ProviderKey::class)]
#[UsesClass(SymfonyAiProviderCatalog::class)]
#[UsesClass(AnthropicDescriptor::class)]
#[UsesClass(OpenAiDescriptor::class)]
#[UsesClass(HuggingFaceDescriptor::class)]
final class HeldCredentialsTest extends TestCase
{
    private const string NAME = 'INTAKT_TEST_API_KEY';
    private const string BODY = 'abcdefgh12345678';
    private const string KEPT_KEY = 'sk-ant-'.self::BODY;

    private string $vaultDirectory;

    public function testAVariableWithAnotherValueInEachSourceHoldsEveryValue(): void
    {
        putenv(self::NAME.'=process-credential-canary');
        $_ENV[self::NAME] = 'env-credential-canary';
        $_SERVER[self::NAME] = 'server-credential-canary';

        self::assertSame(
            ['process-credential-canary', 'env-credential-canary', 'server-credential-canary'],
            new HeldCredentials([self::NAME])->values(),
        );
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

    public function testAVaultWhoseRevealThrowsABareRuntimeExceptionHoldsNothingInsteadOfFailing(): void
    {
        self::assertSame([], new HeldCredentials([self::NAME], $this->vaultRevealThrowing(new RuntimeException('no key')))->values());
    }

    public function testAVaultWhoseRevealThrowsASubclassOfRuntimeExceptionLetsItPassThrough(): void
    {
        $this->expectException(UnexpectedValueException::class);

        new HeldCredentials([self::NAME], $this->vaultRevealThrowing(new UnexpectedValueException('a bug')))->values();
    }

    public function testAVaultWhoseRevealThrowsALogicExceptionLetsItPassThrough(): void
    {
        $this->expectException(LogicException::class);

        new HeldCredentials([self::NAME], $this->vaultRevealThrowing(new LogicException('a bug')))->values();
    }

    public function testAKeyKeptFromTheBrowserIsHeld(): void
    {
        $kept = self::createStub(KeptProviderKeys::class);
        $kept->method('read')->willReturn(ProviderKey::recognise(self::KEPT_KEY, new SymfonyAiProviderCatalog()));

        self::assertSame([self::KEPT_KEY], new HeldCredentials([self::NAME], null, $kept)->values());
    }

    public function testAKeptFileThatCannotBeReadHoldsNothingInsteadOfFailing(): void
    {
        $kept = self::createStub(KeptProviderKeys::class);
        $kept->method('read')->willThrowException(new KeptProviderKeyUnreadable());

        self::assertSame([], new HeldCredentials([self::NAME], null, $kept)->values());
    }

    protected function setUp(): void
    {
        $this->vaultDirectory = sys_get_temp_dir().'/intakt-held-credentials-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        unset($_SERVER[self::NAME], $_SERVER[self::NAME.'_TEAM'], $_SERVER['OTHER_'.self::NAME], $_SERVER[self::NAME.'S'], $_ENV[self::NAME]);
        putenv(self::NAME);
        new Filesystem()->remove($this->vaultDirectory);
    }

    private function vaultRevealThrowing(Throwable $failure): SodiumVault
    {
        $vault = $this->createStub(SodiumVault::class);
        $vault->method('list')->willReturn([self::NAME => null]);
        $vault->method('reveal')->willThrowException($failure);

        return $vault;
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
