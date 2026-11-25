<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EnvironmentVariables;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\ProviderKeySource;
use LogicException;
use RuntimeException;
use UnexpectedValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

// Serial because the tests set process-wide variables.
#[CoversClass(ProviderKeySource::class)]
#[UsesClass(Account::class)]
#[UsesClass(EnvironmentVariables::class)]
#[UsesClass(Provider::class)]
#[Group('serial')]
final class ProviderKeySourceTest extends TestCase
{
    private const string NAME = 'PROVIDER_KEY_SOURCE_TEST_VARIABLE';

    private string $directory;

    public function testAnEmptyKeyCountsAsMissing(): void
    {
        $_SERVER[self::NAME] = '';

        self::assertNull(ProviderKeySource::environmentValue(self::NAME));
        self::assertFalse($this->source(environment: ProviderKeySource::environmentValue(...))->isSet(self::NAME));
    }

    public function testTheCompatibleEndpointNeedsBothItsAddressAndItsKey(): void
    {
        $source = $this->source(environment: static fn (string $name): ?string => 'OPENAI_COMPATIBLE_BASE_URL' === $name ? 'http://localhost:11434/v1' : null);

        $missing = $source->missing(new Account(Provider::OpenAiCompatible, Account::DEFAULT));

        self::assertSame(['OPENAI_COMPATIBLE_API_KEY'], [...$missing]);
        self::assertSame(
            'Provider "openai_compatible" is disabled: set OPENAI_COMPATIBLE_API_KEY to enable it.',
            Provider::OpenAiCompatible->disabledMessage($missing),
        );
    }

    public function testAKeyInTheEnvironmentArrayWinsOverAnEmptyServerValue(): void
    {
        $_SERVER[self::NAME] = '';
        $_ENV[self::NAME] = 'test-value';

        self::assertSame('test-value', ProviderKeySource::environmentValue(self::NAME));
    }

    public function testAnEmptyKeyInTheSecretsVaultCountsAsMissing(): void
    {
        $this->sealed(self::NAME, '');

        self::assertFalse($this->source(vault: ProviderKeySource::openVault($this->directory, null))->isSet(self::NAME));
    }

    public function testAMalformedDecryptionSecretLeavesAProviderWithItsKeyInTheEnvironmentEnabled(): void
    {
        $this->sealed('UNRELATED_VAULT_ENTRY', 'test-value');
        $vault = ProviderKeySource::openVault($this->directory, $this->malformedSecret());

        self::assertTrue($this->source(
            vault: $vault,
            environment: static fn (): string => 'test-value',
        )->isSet(self::NAME));
    }

    public function testAKeyStoredOnlyInTheVaultBehindAMalformedDecryptionSecretCountsAsMissing(): void
    {
        $this->sealed(self::NAME, 'test-value');
        $vault = ProviderKeySource::openVault($this->directory, $this->malformedSecret());

        self::assertFalse($this->source(vault: $vault)->isSet(self::NAME));
    }

    public function testAKeyInAVaultBehindAUrlSafeEncodedDecryptionSecretEnablesItsProvider(): void
    {
        // All bits set, so the encoded key is made of the characters only the URL-safe alphabet has.
        $secretKey = str_repeat("\xFF", SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, sodium_crypto_box_publickey_from_secretkey($secretKey));
        $sealing = new SodiumVault($this->directory, $keyPair);
        $sealing->generateKeys();
        $sealing->seal(self::NAME, 'test-value');
        $secret = strtr(base64_encode($keyPair), '+/', '-_');

        self::assertTrue($this->source(vault: ProviderKeySource::openVault($this->directory, $secret))->isSet(self::NAME));
    }

    public function testAKeyStoredOnlyInAVaultWithoutAnyUsableDecryptionKeyCountsAsMissing(): void
    {
        $this->sealed(self::NAME, 'test-value');
        unlink($this->directory.'/'.basename($this->directory).'.encrypt.public.php');
        unlink($this->directory.'/'.basename($this->directory).'.decrypt.private.php');

        // Only characters base64 ignores, so the secret decodes to an empty key.
        self::assertFalse($this->source(vault: ProviderKeySource::openVault($this->directory, '!!!'))->isSet(self::NAME));
    }

    public function testAKeptKeyCountsWhenNeitherTheEnvironmentNorTheVaultHasOne(): void
    {
        self::assertTrue($this->source(kept: [self::NAME => 'test-value'])->isSet(self::NAME));
    }

    public function testAKeptKeyIsIgnoredBehindAVaultSecretThatCannotBeRevealed(): void
    {
        $this->sealed(self::NAME, 'test-value');
        $vault = ProviderKeySource::openVault($this->directory, $this->malformedSecret());

        self::assertFalse($this->source(
            vault: $vault,
            kept: [self::NAME => 'kept-value'],
        )->isSet(self::NAME));
    }

    public function testAVaultWhoseRevealThrowsABareRuntimeExceptionCountsTheKeyAsMissing(): void
    {
        self::assertFalse($this->source(vault: $this->vaultRevealThrowing(new RuntimeException('no key')))->isSet(self::NAME));
    }

    public function testAVaultWhoseRevealThrowsASubclassOfRuntimeExceptionLetsItPassThrough(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->source(vault: $this->vaultRevealThrowing(new UnexpectedValueException('a bug')))->isSet(self::NAME);
    }

    public function testAVaultWhoseRevealThrowsALogicExceptionLetsItPassThrough(): void
    {
        $this->expectException(LogicException::class);

        $this->source(vault: $this->vaultRevealThrowing(new LogicException('a bug')))->isSet(self::NAME);
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/provider-key-source-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        unset($_SERVER[self::NAME], $_ENV[self::NAME]);
        putenv(self::NAME);
        new Filesystem()->remove($this->directory);
    }

    private function vaultRevealThrowing(Throwable $failure): SodiumVault
    {
        $vault = $this->createStub(SodiumVault::class);
        $vault->method('list')->willReturn([self::NAME => null]);
        $vault->method('reveal')->willThrowException($failure);

        return $vault;
    }

    /**
     * @param array<string, string>      $kept
     * @param (Closure(string): ?string) $environment
     */
    private function source(?SodiumVault $vault = null, array $kept = [], ?Closure $environment = null): ProviderKeySource
    {
        return new ProviderKeySource($vault ?? new SodiumVault($this->directory), $kept, $environment ?? static fn (): ?string => null);
    }

    // Sealed in the folder the test names, because the unusable-key test removes the key files by that name.
    private function sealed(string $name, string $value): void
    {
        $vault = new SodiumVault($this->directory);
        $vault->generateKeys();
        $vault->seal($name, $value);
    }

    // Base64 of the wrong length, because libsodium rejects it with an exception, where a right-length wrong key only fails to decrypt.
    private function malformedSecret(): string
    {
        return base64_encode('too short for a key pair');
    }
}
