<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\KeepProviderKey;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\KeyNotRecognised;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Contract\ProviderKeyAlreadyKept;
use Intakt\Core\Ai\Infrastructure\KeptProviderKeyFile;
use Intakt\Core\Dashboard\Infrastructure\FormPress;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeepProviderKey;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeepProviderKeyController;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeyNotChecked;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeyRefusedByProvider;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnthropicDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\HuggingFaceDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\OpenAiDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use Intakt\Plugin\Vendor\Contract\KeyCheck;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[CoversClass(KeepProviderKeyController::class)]
#[UsesClass(FormPress::class)]
#[UsesClass(DashboardLedger::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(KeepProviderKey::class)]
#[UsesClass(KeptProviderKeyFile::class)]
#[UsesClass(ProviderKey::class)]
#[UsesClass(ConfiguredKey::class)]
#[UsesClass(SymfonyAiProviderCatalog::class)]
#[UsesClass(AnthropicDescriptor::class)]
#[UsesClass(OpenAiDescriptor::class)]
#[UsesClass(HuggingFaceDescriptor::class)]
#[UsesClass(KeyNotRecognised::class)]
#[UsesClass(KeyNotChecked::class)]
#[UsesClass(KeyRefusedByProvider::class)]
#[UsesClass(KeyCheckOutcome::class)]
#[UsesClass(ProviderKeyAlreadyKept::class)]
final class KeepProviderKeyControllerTest extends WebTestCase
{
    // Built from parts, so no key-shaped literal sits in the repository.
    private const string BODY = 'abcdefgh12345678';
    private const string OTHER_BODY = 'zyxwvuts87654321';

    private string $directory = '';

    public function testAPostWithoutAValidFormTokenIsRefusedAndKeepsNoKey(): void
    {
        $browser = $this->browser(KeyCheckOutcome::Accepted);

        $browser->request('POST', '/setup/provider-key', ['_token' => 'wrong', 'key' => $this->key()]);

        self::assertResponseStatusCodeSame(400);
        self::assertFileDoesNotExist($this->directory.'/provider-key.json');
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so no key was kept."', BadRequestHttpException::class));
    }

    public function testASecondKeyIsRefusedWhileAKeptKeyIsConfiguredAndTheKeptKeyStaysUnchanged(): void
    {
        $browser = $this->browser(KeyCheckOutcome::Accepted);
        $this->file()->keep(ProviderKey::recognise($this->key(), new SymfonyAiProviderCatalog()));

        $this->post($browser, 'sk-'.self::OTHER_BODY);

        self::assertSelectorTextContains('.flash-error', 'A provider key is already configured');
        self::assertSame($this->key(), $this->file()->read()?->secret());
    }

    public function testAKeyKeptByAnotherRequestWhileThisKeyIsCheckedIsRefusedAsAlreadyConfigured(): void
    {
        $file = $this->file();
        $first = $this->key();
        $browser = $this->browser(static function () use ($file, $first): KeyCheckOutcome {
            $file->keep(ProviderKey::recognise($first, new SymfonyAiProviderCatalog()));

            return KeyCheckOutcome::Accepted;
        });

        $this->post($browser, 'sk-'.self::OTHER_BODY);

        self::assertSelectorTextContains('.flash-error', 'A provider key is already configured');
        self::assertSame($first, $file->read()?->secret());
    }

    public function testASecondKeyIsRefusedWhileAProviderHasAnEnabledAccount(): void
    {
        $browser = $this->browser(KeyCheckOutcome::Accepted, true);

        $this->post($browser, $this->key());

        self::assertSelectorTextContains('.flash-error', 'A provider key is already configured');
        self::assertFileDoesNotExist($this->directory.'/provider-key.json');
    }

    public function testAKeyTheProviderRefusesIsNotKeptAndThePageSaysTheProviderRefusedIt(): void
    {
        $browser = $this->browser(KeyCheckOutcome::Refused);

        $this->post($browser, $this->key());

        self::assertSelectorTextContains('.flash-error', 'The provider refused the key, so it was not kept.');
        self::assertFileDoesNotExist($this->directory.'/provider-key.json');
    }

    public function testAKeyTheCheckCouldNotReachIsNotKeptAndThePageSaysItWasNotChecked(): void
    {
        $browser = $this->browser(KeyCheckOutcome::NotChecked);

        $this->post($browser, $this->key());

        self::assertSelectorTextContains('.flash-error', 'could not be asked to check the key');
        self::assertFileDoesNotExist($this->directory.'/provider-key.json');
    }

    public function testAKeyOfNoKnownProviderIsRefusedAndNothingIsKept(): void
    {
        $browser = $this->browser(KeyCheckOutcome::Accepted);

        $this->post($browser, 'not-a-key');

        self::assertSelectorTextContains('.flash-error', 'The key has the form of no listed provider (Anthropic, OpenAI, Hugging Face). No key was kept.');
        self::assertFileDoesNotExist($this->directory.'/provider-key.json');
    }

    public function testAnAcceptedKeyIsKeptAndTheResponseNamesItsKindButNeverContainsTheKey(): void
    {
        $browser = $this->browser(KeyCheckOutcome::Accepted);

        $this->post($browser, $this->key());

        self::assertSelectorTextContains('.flash-success', 'The anthropic key ending in 5678 is kept.');
        self::assertSame($this->key(), $this->file()->read()?->secret());
        self::assertStringNotContainsString($this->key(), (string) $browser->getResponse()->getContent());
        self::assertStringNotContainsString(self::BODY, (string) $browser->getResponse()->getContent());
    }

    public function testNoLogRecordWrittenWhileKeepingOrRefusingAKeyContainsIt(): void
    {
        $browser = $this->browser(KeyCheckOutcome::Accepted);

        $this->post($browser, $this->key());
        $this->post($browser, $this->key());

        $records = LogRecords::ofThisRun()?->all() ?? [];
        foreach ($records as $record) {
            self::assertStringNotContainsString(self::BODY, $record['message']);
        }
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/'.uniqid('keep-key-page-', true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        new Filesystem()->remove($this->directory);
    }

    private function file(): KeptProviderKeyFile
    {
        return new KeptProviderKeyFile($this->directory, new SymfonyAiProviderCatalog());
    }

    /**
     * @param KeyCheckOutcome|Closure(): KeyCheckOutcome $outcome
     */
    private function browser(KeyCheckOutcome|Closure $outcome, bool $anAccountIsEnabled = false): KernelBrowser
    {
        self::ensureKernelShutdown();
        $browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $browser->disableReboot();
        $container = self::getContainer();
        // Answered without the database, because the page the redirect leads to reads the ledger and this test creates no schema.
        $container->set(Ledger::class, new DashboardLedger());
        $container->set(ReplayMarks::class, new ReplayMarks(new ReplayMarkBus()));
        $check = self::createStub(KeyCheck::class);
        $check->method('check')->willReturnCallback($outcome instanceof Closure ? $outcome : static fn (): KeyCheckOutcome => $outcome);
        $container->set(KeepProviderKey::class, new KeepProviderKey($check, $this->file(), new SymfonyAiProviderCatalog()));
        $accounts = self::createStub(EnabledAccounts::class);
        $accounts->method('of')->willReturnCallback(static fn (Provider $provider): array => $anAccountIsEnabled ? [new Account($provider, Account::DEFAULT)] : []);
        $container->set(EnabledAccounts::class, $accounts);

        return $browser;
    }

    private function key(): string
    {
        return 'sk-ant-'.self::BODY;
    }

    private function post(KernelBrowser $browser, string $key): void
    {
        $browser->request('POST', '/setup/provider-key', ['_token' => $this->token($browser), 'key' => $key]);
        self::assertResponseRedirects('/setup');
        $browser->followRedirect();
    }

    // Issued for a session of its own that the browser then holds, because the token belongs to the session.
    private function token(KernelBrowser $browser): string
    {
        $container = self::getContainer();
        $factory = $container->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $factory);
        $session = $factory->createSession();
        $request = new Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);
        $tokens = $container->get('security.csrf.token_manager');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokens);
        $value = $tokens->getToken(KeepProviderKeyController::TOKEN_ID)->getValue();
        $session->save();
        $browser->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $value;
    }
}
