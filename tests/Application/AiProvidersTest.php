<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use ArrayObject;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Infrastructure\KeptProviderKeyFile;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\RebuiltContainer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Covers nothing because the behavior is in config/packages/ai.php, which is not a class; serial because its vaults create config/secrets/, which the workers share, and a container another worker compiles meanwhile keeps the vault loader for good.
#[CoversNothing]
#[Group('serial')]
final class AiProvidersTest extends KernelTestCase
{
    private const string BODY = 'abcdefgh12345678';
    private const string KEPT_KEY = 'sk-ant-'.self::BODY;

    private const array VARIABLES = [
        'ANTHROPIC_API_KEY',
        'OPENAI_API_KEY',
        'OPENAI_COMPATIBLE_BASE_URL',
        'OPENAI_COMPATIBLE_API_KEY',
        'CLAUDE_CODE_OAUTH_TOKEN',
    ];

    // A runtime environment of its own, so the test vault never mixes with a real one under config/secrets/.
    private const string VAULT_ENVIRONMENT_PREFIX = 'ai_providers_test';

    /** @var array<string, array{server: mixed, env: mixed, process: string|false}> */
    private array $saved = [];

    private CompiledContainers $containers;

    public static function tearDownAfterClass(): void
    {
        self::compiledContainers()->remove();
        parent::tearDownAfterClass();
    }

    public function testEveryProviderWithItsKeysIsConfiguredAndNoneIsDisabled(): void
    {
        $container = $this->bootWith($this->allSet());

        self::assertTrue($container->has('ai.platform.anthropic'));
        self::assertTrue($container->has('ai.platform.openai'));
        self::assertTrue($container->has('ai.platform.generic.openai_compatible'));
        self::assertTrue($container->has(Provider::ClaudeCode->platformId(new Account(Provider::ClaudeCode, Account::DEFAULT))));
        self::assertSame([], $container->getParameter(AiServices::DISABLED_PROVIDERS));
    }

    public function testTheSubscriptionPlatformIsBuiltWhenItsLoginTokenIsSet(): void
    {
        $container = $this->bootWith($this->allSet());

        self::assertInstanceOf(PlatformInterface::class, $container->get(Provider::ClaudeCode->platformId(new Account(Provider::ClaudeCode, Account::DEFAULT))));
    }

    public function testTheSubscriptionProviderIsDisabledUntilItsLoginTokenIsSet(): void
    {
        $values = $this->allSet();
        unset($values['CLAUDE_CODE_OAUTH_TOKEN']);

        $container = $this->bootWith($values);

        self::assertFalse($container->has(Provider::ClaudeCode->platformId(new Account(Provider::ClaudeCode, Account::DEFAULT))));
        self::assertSame(
            ['claude_code' => 'Provider "claude_code" is disabled: set CLAUDE_CODE_OAUTH_TOKEN to enable it.'],
            $container->getParameter(AiServices::DISABLED_PROVIDERS),
        );
    }

    public function testAProviderWithoutItsKeyIsDisabledWithAMessageAndTheKernelBoots(): void
    {
        $values = $this->allSet();
        unset($values['OPENAI_API_KEY']);

        $container = $this->bootWith($values);

        self::assertFalse($container->has('ai.platform.openai'));
        self::assertTrue($container->has('ai.platform.anthropic'));
        self::assertSame(
            ['openai' => 'Provider "openai" is disabled: set OPENAI_API_KEY to enable it.'],
            $container->getParameter(AiServices::DISABLED_PROVIDERS),
        );
    }

    public function testAnEmptyKeyCountsAsMissing(): void
    {
        $container = $this->bootWith([...$this->allSet(), 'OPENAI_API_KEY' => '']);

        self::assertFalse($container->has('ai.platform.openai'));
        $disabled = $container->getParameter(AiServices::DISABLED_PROVIDERS);

        self::assertIsArray($disabled);
        self::assertArrayHasKey('openai', $disabled);
    }

    public function testTheCompatibleEndpointNeedsBothItsAddressAndItsKey(): void
    {
        $values = $this->allSet();
        unset($values['OPENAI_COMPATIBLE_API_KEY']);

        $container = $this->bootWith($values);

        self::assertFalse($container->has('ai.platform.generic.openai_compatible'));
        self::assertSame(
            ['openai_compatible' => 'Provider "openai_compatible" is disabled: set OPENAI_COMPATIBLE_API_KEY to enable it.'],
            $container->getParameter(AiServices::DISABLED_PROVIDERS),
        );
    }

    public function testWithoutAnyKeyEveryProviderIsDisabledAndTheKernelStillBoots(): void
    {
        $container = $this->bootWith([]);

        $disabled = $container->getParameter(AiServices::DISABLED_PROVIDERS);

        self::assertIsArray($disabled);
        self::assertSame(['anthropic', 'openai', 'openai_compatible', 'claude_code'], array_keys($disabled));
    }

    public function testAKeyInTheEnvironmentArrayWinsOverAnEmptyServerValue(): void
    {
        $_SERVER['OPENAI_API_KEY'] = '';
        $_ENV['OPENAI_API_KEY'] = 'test-value';

        $container = $this->bootWith(array_diff_key($this->allSet(), ['OPENAI_API_KEY' => true]), keep: ['OPENAI_API_KEY']);

        self::assertTrue($container->has('ai.platform.openai'));
    }

    public function testAKeptKeyEnablesItsProviderWhenTheEnvironmentHasNone(): void
    {
        $this->keepKey(self::KEPT_KEY);

        $container = $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertTrue($container->has('ai.platform.anthropic'));
        self::assertSame([], $container->getParameter(AiServices::DISABLED_PROVIDERS));
    }

    public function testAValueInTheEnvironmentWinsOverTheKeptKey(): void
    {
        $this->keepKey(self::KEPT_KEY);

        $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => 'value-from-the-environment']);

        self::assertSame('value-from-the-environment', $this->resolvedVariable('ANTHROPIC_API_KEY'));
    }

    public function testAKeptKeyIsTheVariableWhenTheEnvironmentHasNone(): void
    {
        $this->keepKey(self::KEPT_KEY);

        $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertSame(self::KEPT_KEY, $this->resolvedVariable('ANTHROPIC_API_KEY'));
    }

    public function testASecretInTheVaultWinsOverTheKeptKey(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('ANTHROPIC_API_KEY', 'value-from-the-vault');
        $_SERVER['APP_RUNTIME_ENV'] = $this->vaultEnvironment();
        $this->keepKey(self::KEPT_KEY);

        $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertSame('value-from-the-vault', $this->resolvedVariable('ANTHROPIC_API_KEY'));
    }

    public function testTheAnthropicPlatformSendsTheKeptKeyToTheProvider(): void
    {
        $this->keepKey(self::KEPT_KEY);
        $container = $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);
        $sent = new ArrayObject();
        $provider = static function (string $method, string $url, array $options) use ($sent): MockResponse {
            $headers = $options['normalized_headers'] ?? [];
            $sent->append(is_array($headers) ? $headers['x-api-key'] ?? null : null);

            return new MockResponse(json_encode([
                'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        };
        self::getContainer()->set('http_client', new MockHttpClient($provider));

        $platform = $container->get('ai.platform.anthropic');
        self::assertInstanceOf(PlatformInterface::class, $platform);
        $platform->invoke('claude-haiku-5-5', new MessageBag(Message::ofUser('Answer with the single word: ok')))->asText();

        self::assertSame([['x-api-key: '.self::KEPT_KEY]], $sent->getArrayCopy());
    }

    public function testAKeyStoredOnlyInTheSecretsVaultEnablesItsProvider(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('ANTHROPIC_API_KEY', 'test-value');
        $_SERVER['APP_RUNTIME_ENV'] = $this->vaultEnvironment();

        $container = $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertTrue($container->has('ai.platform.anthropic'));
        self::assertSame([], $container->getParameter(AiServices::DISABLED_PROVIDERS));
        self::assertInstanceOf(PlatformInterface::class, $container->get('ai.platform.anthropic'));
    }

    public function testAnEmptyKeyInTheSecretsVaultCountsAsMissing(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('ANTHROPIC_API_KEY', '');
        $_SERVER['APP_RUNTIME_ENV'] = $this->vaultEnvironment();

        $container = $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertFalse($container->has('ai.platform.anthropic'));
        self::assertSame(
            ['anthropic' => 'Provider "anthropic" is disabled: set ANTHROPIC_API_KEY to enable it.'],
            $container->getParameter(AiServices::DISABLED_PROVIDERS),
        );
    }

    public function testAMalformedDecryptionSecretLeavesAProviderWithItsKeyInTheEnvironmentEnabled(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('UNRELATED_VAULT_ENTRY', 'test-value');
        $_SERVER['APP_RUNTIME_ENV'] = $this->vaultEnvironment();
        $this->useMalformedDecryptionSecret();

        $container = $this->bootWith($this->allSet());

        self::assertTrue($container->has('ai.platform.anthropic'));
        self::assertTrue($container->has('ai.platform.openai'));
        self::assertTrue($container->has('ai.platform.generic.openai_compatible'));
        self::assertSame([], $container->getParameter(AiServices::DISABLED_PROVIDERS));
    }

    public function testAKeyStoredOnlyInTheVaultBehindAMalformedDecryptionSecretCountsAsMissing(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('ANTHROPIC_API_KEY', 'test-value');
        $_SERVER['APP_RUNTIME_ENV'] = $this->vaultEnvironment();
        $this->useMalformedDecryptionSecret();

        $container = $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertFalse($container->has('ai.platform.anthropic'));
        self::assertSame(
            ['anthropic' => 'Provider "anthropic" is disabled: set ANTHROPIC_API_KEY to enable it.'],
            $container->getParameter(AiServices::DISABLED_PROVIDERS),
        );
    }

    public function testAKeyInAVaultBehindAUrlSafeEncodedDecryptionSecretEnablesItsProvider(): void
    {
        // All bits set, so the encoded key is made of the characters only the URL-safe alphabet has.
        $secretKey = str_repeat("\xFF", SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, sodium_crypto_box_publickey_from_secretkey($secretKey));
        $vault = new SodiumVault($this->vaultDirectory(), $keyPair);
        $vault->generateKeys();
        $vault->seal('ANTHROPIC_API_KEY', 'test-value');
        $_SERVER['APP_RUNTIME_ENV'] = $this->vaultEnvironment();
        $_SERVER['SYMFONY_DECRYPTION_SECRET'] = strtr(base64_encode($keyPair), '+/', '-_');

        $container = $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertTrue($container->has('ai.platform.anthropic'));
        self::assertSame([], $container->getParameter(AiServices::DISABLED_PROVIDERS));
    }

    public function testAKeyStoredOnlyInAVaultWithoutAnyUsableDecryptionKeyCountsAsMissing(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('ANTHROPIC_API_KEY', 'test-value');
        unlink($this->vaultDirectory().'/'.$this->vaultEnvironment().'.encrypt.public.php');
        unlink($this->vaultDirectory().'/'.$this->vaultEnvironment().'.decrypt.private.php');
        $_SERVER['APP_RUNTIME_ENV'] = $this->vaultEnvironment();
        // Only characters base64 ignores, so the secret decodes to an empty key.
        $_SERVER['SYMFONY_DECRYPTION_SECRET'] = '!!!';

        $container = $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertFalse($container->has('ai.platform.anthropic'));
        self::assertSame(
            ['anthropic' => 'Provider "anthropic" is disabled: set ANTHROPIC_API_KEY to enable it.'],
            $container->getParameter(AiServices::DISABLED_PROVIDERS),
        );
    }

    public function testAVaultSecretBehindADecryptionSecretOfTheWrongLengthDisablesItsProviderDespiteAKeptKey(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('ANTHROPIC_API_KEY', 'value-from-the-vault');
        $_SERVER['APP_RUNTIME_ENV'] = $this->vaultEnvironment();
        $this->useMalformedDecryptionSecret();
        $this->keepKey(self::KEPT_KEY);

        $container = $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertFalse($container->has('ai.platform.anthropic'));
        self::assertSame(
            ['anthropic' => 'Provider "anthropic" is disabled: set ANTHROPIC_API_KEY to enable it.'],
            $container->getParameter(AiServices::DISABLED_PROVIDERS),
        );
    }

    public function testAVaultSecretWithoutAnyUsableDecryptionKeyDisablesItsProviderDespiteAKeptKey(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('ANTHROPIC_API_KEY', 'value-from-the-vault');
        unlink($this->vaultDirectory().'/'.$this->vaultEnvironment().'.encrypt.public.php');
        unlink($this->vaultDirectory().'/'.$this->vaultEnvironment().'.decrypt.private.php');
        $_SERVER['APP_RUNTIME_ENV'] = $this->vaultEnvironment();
        // Only characters base64 ignores, so the secret decodes to an empty key.
        $_SERVER['SYMFONY_DECRYPTION_SECRET'] = '!!!';
        $this->keepKey(self::KEPT_KEY);

        $container = $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertFalse($container->has('ai.platform.anthropic'));
        self::assertSame(
            ['anthropic' => 'Provider "anthropic" is disabled: set ANTHROPIC_API_KEY to enable it.'],
            $container->getParameter(AiServices::DISABLED_PROVIDERS),
        );
    }

    public function testAKeptKeyIsUsedBehindAVaultSecretThatAWrongKeyOfTheRightLengthCannotOpen(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('ANTHROPIC_API_KEY', 'value-from-the-vault');
        $_SERVER['APP_RUNTIME_ENV'] = $this->vaultEnvironment();
        // Another key pair of the right length, which libsodium takes and only fails to decrypt with, so the vault's loader returns nothing where a wrong length throws.
        $secretKey = str_repeat("\x01", SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $_SERVER['SYMFONY_DECRYPTION_SECRET'] = base64_encode(sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, sodium_crypto_box_publickey_from_secretkey($secretKey)));
        $this->keepKey(self::KEPT_KEY);

        $container = $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertSame([], $container->getParameter(AiServices::DISABLED_PROVIDERS));
        self::assertSame(self::KEPT_KEY, $this->resolvedVariable('ANTHROPIC_API_KEY'));
    }

    public function testAKeptKeyIsUsedBehindAVaultSecretWhosePrivateKeyFileIsMissing(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('ANTHROPIC_API_KEY', 'value-from-the-vault');
        unlink($this->vaultDirectory().'/'.$this->vaultEnvironment().'.decrypt.private.php');
        $_SERVER['APP_RUNTIME_ENV'] = $this->vaultEnvironment();
        $this->keepKey(self::KEPT_KEY);

        $container = $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertSame([], $container->getParameter(AiServices::DISABLED_PROVIDERS));
        self::assertSame(self::KEPT_KEY, $this->resolvedVariable('ANTHROPIC_API_KEY'));
    }

    public function testAKeptKeyIsUsedBesideAnEmptySecretInTheVault(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('ANTHROPIC_API_KEY', '');
        $_SERVER['APP_RUNTIME_ENV'] = $this->vaultEnvironment();
        $this->keepKey(self::KEPT_KEY);

        $container = $this->bootWith([...$this->allSet(), 'ANTHROPIC_API_KEY' => '']);

        self::assertSame([], $container->getParameter(AiServices::DISABLED_PROVIDERS));
        self::assertSame(self::KEPT_KEY, $this->resolvedVariable('ANTHROPIC_API_KEY'));
    }

    protected function setUp(): void
    {
        $this->containers = self::compiledContainers();

        foreach ([...self::VARIABLES, 'APP_RUNTIME_ENV', 'SYMFONY_DECRYPTION_SECRET', 'INTAKT_PROVIDER_KEY_DIR'] as $name) {
            $this->saved[$name] = [
                'server' => $_SERVER[$name] ?? null,
                'env' => $_ENV[$name] ?? null,
                'process' => getenv($name),
            ];
        }

        $_SERVER['INTAKT_PROVIDER_KEY_DIR'] = $this->keptKeyDirectory();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $before) {
            $this->unsetVariable($name);
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

        parent::tearDown();
        $this->containers->restoreTheSharedCache();
        new Filesystem()->remove([$this->vaultDirectory(), $this->keptKeyDirectory()]);
        $this->removeTheVaultsFolderWhenEmpty();
    }

    // Base64 of the wrong length, because libsodium rejects it with an exception, where a right-length wrong key only fails to decrypt.
    private function useMalformedDecryptionSecret(): void
    {
        $_SERVER['SYMFONY_DECRYPTION_SECRET'] = base64_encode('too short for a key pair');
    }

    /**
     * @return array<string, string>
     */
    private function allSet(): array
    {
        $values = [];
        foreach (self::VARIABLES as $name) {
            $values[$name] = str_ends_with($name, 'BASE_URL') ? 'http://localhost:11434/v1' : 'test-value';
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @param list<string>          $keep
     */
    private function bootWith(array $values, array $keep = []): RebuiltContainer
    {
        foreach (self::VARIABLES as $name) {
            if (in_array($name, $keep, true)) {
                continue;
            }

            $this->unsetVariable($name);
            if (array_key_exists($name, $values)) {
                $_SERVER[$name] = $values[$name];
            }
        }

        self::ensureKernelShutdown();
        // The build decides on the variables and the vault, so every set of them needs a container of its own.
        $this->containers->useFor($this->buildInputs());
        self::bootKernel();

        return new RebuiltContainer(self::getContainer());
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'ai-providers');
    }

    // Every variable as each source holds it rather than as the config reads it, so a test of which source wins never reuses a container built for another.
    /** @return array<mixed> */
    private function buildInputs(): array
    {
        $variables = [];
        foreach (array_keys($this->saved) as $name) {
            $variables[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        }

        $files = glob($this->vaultDirectory().'/*');
        $vault = [];
        foreach (false === $files ? [] : $files as $file) {
            $vault[basename($file)] = file_get_contents($file);
        }

        $kept = $this->keptKeyDirectory().'/provider-key.json';

        return [$variables, $vault, is_file($kept) ? file_get_contents($kept) : null];
    }

    // Reflection, because the container resolves a variable only for a service argument and its method for that is protected.
    private function resolvedVariable(string $name): mixed
    {
        $container = self::$kernel?->getContainer();
        self::assertNotNull($container);

        return new ReflectionMethod($container, 'getEnv')->invoke($container, $name);
    }

    private function keepKey(string $key): void
    {
        $providers = new SymfonyAiProviderCatalog();
        new KeptProviderKeyFile($this->keptKeyDirectory(), $providers)->keep(ProviderKey::recognise($key, $providers));
    }

    // Under the worker's own TMPDIR, so no test reads or writes the install's kept key in var/data and none removes another worker's.
    private function keptKeyDirectory(): string
    {
        return sys_get_temp_dir().'/ai-providers-test-kept-key';
    }

    private function vaultDirectory(): string
    {
        return self::createKernel()->getProjectDir().'/config/secrets/'.$this->vaultEnvironment();
    }

    // Because every test worker has a TMPDIR of its own, each gets a vault folder of its own and none removes another's.
    private function vaultEnvironment(): string
    {
        return self::VAULT_ENVIRONMENT_PREFIX.'_'.hash('xxh128', sys_get_temp_dir());
    }

    private function unsetVariable(string $name): void
    {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }

    // Because the framework keeps its vault env loader whenever config/secrets/ exists, so a folder left empty here would change every container built after this test.
    private function removeTheVaultsFolderWhenEmpty(): void
    {
        $vaults = dirname($this->vaultDirectory());
        if (is_dir($vaults) && [] === array_diff((array) scandir($vaults), ['.', '..'])) {
            rmdir($vaults);
        }
    }
}
