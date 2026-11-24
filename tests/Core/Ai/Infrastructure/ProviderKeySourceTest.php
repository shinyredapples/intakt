<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EnvironmentVariables;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\ProviderKeySource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ProviderKeySource::class)]
#[UsesClass(Account::class)]
#[UsesClass(EnvironmentVariables::class)]
#[UsesClass(Provider::class)]
final class ProviderKeySourceTest extends TestCase
{
    private const array VARIABLES = ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY'];

    /** @var array<string, array{server: mixed, env: mixed, process: string|false}> */
    private array $saved = [];

    private string $directory = '';

    public function testAnEmptyKeyCountsAsMissing(): void
    {
        $_SERVER['OPENAI_API_KEY'] = '';

        self::assertSame(['OPENAI_API_KEY'], $this->missingOf(Provider::OpenAi, ''));
    }

    public function testTheCompatibleEndpointNeedsBothItsAddressAndItsKey(): void
    {
        $_SERVER['OPENAI_COMPATIBLE_BASE_URL'] = 'http://localhost:11434/v1';

        self::assertSame(['OPENAI_COMPATIBLE_API_KEY'], $this->missingOf(Provider::OpenAiCompatible, ''));
    }

    public function testAKeyInTheEnvironmentArrayWinsOverAnEmptyServerValue(): void
    {
        $_SERVER['OPENAI_API_KEY'] = '';
        $_ENV['OPENAI_API_KEY'] = 'test-value';

        self::assertSame([], $this->missingOf(Provider::OpenAi, ''));
    }

    public function testAnEmptyKeyInTheSecretsVaultCountsAsMissing(): void
    {
        $this->vaultHolding('ANTHROPIC_API_KEY', '');

        self::assertSame(['ANTHROPIC_API_KEY'], $this->missingOf(Provider::Anthropic, ''));
    }

    /**
     * @return iterable<string, array{Provider, array<string, string>}>
     */
    public static function providersWithTheirKeysInTheEnvironment(): iterable
    {
        yield 'anthropic' => [Provider::Anthropic, ['ANTHROPIC_API_KEY' => 'test-value']];
        yield 'openai' => [Provider::OpenAi, ['OPENAI_API_KEY' => 'test-value']];
        yield 'openai compatible' => [Provider::OpenAiCompatible, ['OPENAI_COMPATIBLE_BASE_URL' => 'http://localhost:11434/v1', 'OPENAI_COMPATIBLE_API_KEY' => 'test-value']];
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('providersWithTheirKeysInTheEnvironment')]
    public function testAMalformedDecryptionSecretLeavesAProviderWithItsKeyInTheEnvironmentEnabled(Provider $provider, array $environment): void
    {
        $this->vaultHolding('UNRELATED_VAULT_ENTRY', 'test-value');
        foreach ($environment as $name => $value) {
            $_SERVER[$name] = $value;
        }

        self::assertSame([], $this->missingOf($provider, $this->malformedDecryptionSecret()));
    }

    public function testAKeyStoredOnlyInTheVaultBehindAMalformedDecryptionSecretCountsAsMissing(): void
    {
        $this->vaultHolding('ANTHROPIC_API_KEY', 'test-value');

        self::assertSame(['ANTHROPIC_API_KEY'], $this->missingOf(Provider::Anthropic, $this->malformedDecryptionSecret()));
    }

    public function testAKeyInAVaultBehindAUrlSafeEncodedDecryptionSecretEnablesItsProvider(): void
    {
        // All bits set, so the encoded key is made of the characters only the URL-safe alphabet has.
        $secretKey = str_repeat("\xFF", SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, sodium_crypto_box_publickey_from_secretkey($secretKey));
        $vault = new SodiumVault($this->vaultDirectory(), $keyPair);
        $vault->generateKeys();
        $vault->seal('ANTHROPIC_API_KEY', 'test-value');
        new Filesystem()->remove($this->vaultDirectory().'/'.basename($this->vaultDirectory()).'.decrypt.private.php');

        self::assertSame([], $this->missingOf(Provider::Anthropic, strtr(base64_encode($keyPair), '+/', '-_')));
    }

    public function testAKeyStoredOnlyInAVaultWithoutAnyUsableDecryptionKeyCountsAsMissing(): void
    {
        $this->vaultHolding('ANTHROPIC_API_KEY', 'test-value');
        new Filesystem()->remove([
            $this->vaultDirectory().'/'.basename($this->vaultDirectory()).'.encrypt.public.php',
            $this->vaultDirectory().'/'.basename($this->vaultDirectory()).'.decrypt.private.php',
        ]);

        // Only characters base64 ignores, so the secret decodes to an empty key.
        self::assertSame(['ANTHROPIC_API_KEY'], $this->missingOf(Provider::Anthropic, '!!!'));
    }

    public function testAKeptKeyCountsWhenTheEnvironmentAndTheVaultHaveNone(): void
    {
        $source = new ProviderKeySource(ProviderKeySource::vaultIn($this->vaultDirectory(), ''), ['ANTHROPIC_API_KEY' => 'sk-ant-kept']);

        self::assertSame([], [...$source->missingOf(new Account(Provider::Anthropic, Account::DEFAULT))]);
    }

    public function testAKeptKeyDoesNotCountBehindAVaultSecretWhoseRevealThrows(): void
    {
        $this->vaultHolding('ANTHROPIC_API_KEY', 'test-value');
        $source = new ProviderKeySource(ProviderKeySource::vaultIn($this->vaultDirectory(), $this->malformedDecryptionSecret()), ['ANTHROPIC_API_KEY' => 'sk-ant-kept']);

        self::assertSame(['ANTHROPIC_API_KEY'], [...$source->missingOf(new Account(Provider::Anthropic, Account::DEFAULT))]);
    }

    public function testAKeptKeyDoesNotCountBehindAVaultSecretWithoutAnyUsableDecryptionKey(): void
    {
        $this->vaultHolding('ANTHROPIC_API_KEY', 'test-value');
        $this->removeTheVaultKeyFiles();

        self::assertSame(['ANTHROPIC_API_KEY'], $this->keptMissingOf('!!!'));
    }

    public function testAKeptKeyCountsBehindAVaultSecretThatAWrongKeyOfTheRightLengthCannotOpen(): void
    {
        $this->vaultHolding('ANTHROPIC_API_KEY', 'test-value');
        // Another key pair of the right length, which libsodium takes and only fails to decrypt with, so the vault's loader returns nothing where a wrong length throws.
        $secretKey = str_repeat("\x01", SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, sodium_crypto_box_publickey_from_secretkey($secretKey));

        self::assertSame([], $this->keptMissingOf(base64_encode($keyPair)));
    }

    public function testAKeptKeyCountsBehindAVaultSecretWhosePrivateKeyFileIsMissing(): void
    {
        $this->vaultHolding('ANTHROPIC_API_KEY', 'test-value');
        new Filesystem()->remove($this->vaultDirectory().'/'.basename($this->vaultDirectory()).'.decrypt.private.php');

        self::assertSame([], $this->keptMissingOf(''));
    }

    public function testAKeptKeyCountsBesideAnEmptySecretInTheVault(): void
    {
        $this->vaultHolding('ANTHROPIC_API_KEY', '');

        self::assertSame([], $this->keptMissingOf(''));
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/provider-key-source-test-'.bin2hex(random_bytes(8));

        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'process' => getenv($name)];
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $before) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
            if (null !== $before['server']) {
                $_SERVER[$name] = $before['server'];
            }
            if (null !== $before['env']) {
                $_ENV[$name] = $before['env'];
            }
            if (false !== $before['process']) {
                putenv($name.'='.$before['process']);
            }
        }

        new Filesystem()->remove($this->vaultDirectory());
    }

    /**
     * @return list<string>
     */
    private function missingOf(Provider $provider, string $decryptionSecret): array
    {
        $source = new ProviderKeySource(ProviderKeySource::vaultIn($this->vaultDirectory(), $decryptionSecret), []);

        return [...$source->missingOf(new Account($provider, Account::DEFAULT))];
    }

    private function vaultHolding(string $name, string $value): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal($name, $value);
    }

    // Base64 of the wrong length, because libsodium rejects it with an exception, where a right-length wrong key only fails to decrypt.
    private function malformedDecryptionSecret(): string
    {
        return base64_encode('too short for a key pair');
    }

    private function removeTheVaultKeyFiles(): void
    {
        new Filesystem()->remove([
            $this->vaultDirectory().'/'.basename($this->vaultDirectory()).'.encrypt.public.php',
            $this->vaultDirectory().'/'.basename($this->vaultDirectory()).'.decrypt.private.php',
        ]);
    }

    /**
     * @return list<string>
     */
    private function keptMissingOf(string $decryptionSecret): array
    {
        $source = new ProviderKeySource(ProviderKeySource::vaultIn($this->vaultDirectory(), $decryptionSecret), ['ANTHROPIC_API_KEY' => 'sk-ant-kept']);

        return [...$source->missingOf(new Account(Provider::Anthropic, Account::DEFAULT))];
    }

    // A folder of its own per test, so no test touches config/secrets/ or the vault of a test running beside it.
    private function vaultDirectory(): string
    {
        return $this->directory;
    }
}
