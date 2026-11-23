<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\KeptProviderKeyNotWritten;
use Intakt\Core\Ai\Contract\KeptProviderKeyUnreadable;
use Intakt\Core\Ai\Contract\KeyNotRecognised;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Contract\ProviderKeyAlreadyKept;
use Intakt\Core\Ai\Infrastructure\KeptProviderKeyFile;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnthropicDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\HuggingFaceDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\OpenAiDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(KeptProviderKeyFile::class)]
#[CoversClass(KeptProviderKeyNotWritten::class)]
#[CoversClass(KeptProviderKeyUnreadable::class)]
#[CoversClass(ProviderKeyAlreadyKept::class)]
#[UsesClass(ProviderKey::class)]
#[UsesClass(ConfiguredKey::class)]
#[UsesClass(KeyNotRecognised::class)]
#[UsesClass(SymfonyAiProviderCatalog::class)]
#[UsesClass(AnthropicDescriptor::class)]
#[UsesClass(OpenAiDescriptor::class)]
#[UsesClass(HuggingFaceDescriptor::class)]
final class KeptProviderKeyFileTest extends TestCase
{
    private const string BODY = 'abcdefgh12345678';

    private string $directory;

    public function testAKeptKeyIsWrittenWithPermissionsOnlyItsOwnerHas(): void
    {
        $this->file()->keep($this->key('sk-ant-'.self::BODY));

        self::assertSame(0o600, fileperms($this->directory.'/provider-key.json') & 0o777);
        self::assertSame(0o700, fileperms($this->directory) & 0o777);
    }

    public function testAKeptKeyReadsBackWithItsProviderAndOnlyItsLastCharactersAsConfiguredState(): void
    {
        $file = $this->file();
        $file->keep($this->key('hf_'.self::BODY));

        $configured = $file->configured();

        self::assertInstanceOf(ConfiguredKey::class, $configured);
        self::assertSame('huggingface', $configured->provider);
        self::assertSame('5678', $configured->lastCharacters);
        self::assertSame('hf_'.self::BODY, $file->read()?->secret());
    }

    public function testAKeyKeptBeforeTheProviderFieldReadsBackFromItsKind(): void
    {
        new Filesystem()->dumpFile($this->directory.'/provider-key.json', '{"kind":"openai","key":"sk-'.self::BODY.'"}');

        self::assertSame('openai', $this->file()->read()?->provider());
    }

    public function testAKeptKeyAlsoNamesItsProviderAsKindSoAnEarlierReleaseReadsIt(): void
    {
        $this->file()->keep($this->key('sk-ant-'.self::BODY));

        self::assertSame(['provider' => 'anthropic', 'kind' => 'anthropic', 'key' => 'sk-ant-'.self::BODY], json_decode((string) file_get_contents($this->directory.'/provider-key.json'), true));
    }

    public function testNoKeptFileMeansNoConfiguredKey(): void
    {
        self::assertNotInstanceOf(ConfiguredKey::class, $this->file()->configured());
        self::assertNotInstanceOf(ProviderKey::class, $this->file()->read());
    }

    public function testAKeptFileThatCannotBeParsedThrowsWithNoneOfItsContentInTheMessage(): void
    {
        new Filesystem()->dumpFile($this->directory.'/provider-key.json', '{"provider":"anthropic","key":"sk-ant-'.self::BODY);

        try {
            $this->file()->read();
            self::fail('The file should be unreadable.');
        } catch (KeptProviderKeyUnreadable $unreadable) {
            self::assertStringNotContainsString(self::BODY, (string) $unreadable);
        }
    }

    public function testAKeptFileWithAnUnlistedProviderOrNoKeyIsUnreadable(): void
    {
        $filesystem = new Filesystem();

        foreach (['{"provider":"other","key":"hf_'.self::BODY.'"}', '{"kind":"anthropic","key":"sk-'.self::BODY.'"}', '{"key":"sk-'.self::BODY.'"}', '{"provider":"openai"}', '{"provider":"openai","key":""}', '[]', '"text"'] as $json) {
            $filesystem->dumpFile($this->directory.'/provider-key.json', $json);

            try {
                $this->file()->configured();
                self::fail('The file should be unreadable: '.$json);
            } catch (KeptProviderKeyUnreadable) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testKeepingAKeyIsRefusedByALinkAtTheFilePathAndNeverWritesThroughIt(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory);
        $target = $this->directory.'/elsewhere';
        $filesystem->dumpFile($target, 'untouched');
        symlink($target, $this->directory.'/provider-key.json');

        try {
            $this->file()->keep($this->key('sk-'.self::BODY));
            self::fail('The link should refuse the key.');
        } catch (ProviderKeyAlreadyKept) {
            self::assertSame('untouched', file_get_contents($target));
            self::assertTrue(is_link($this->directory.'/provider-key.json'));
        }
    }

    public function testKeepingAKeyIsRefusedByADanglingLinkAtTheFilePathAndCreatesNoFileAtItsTarget(): void
    {
        new Filesystem()->mkdir($this->directory);
        $target = $this->directory.'-outside';
        symlink($target, $this->directory.'/provider-key.json');

        try {
            $this->file()->keep($this->key('sk-ant-'.self::BODY));
            self::fail('The dangling link should refuse the key.');
        } catch (ProviderKeyAlreadyKept) {
            self::assertFileDoesNotExist($target);
            self::assertTrue(is_link($this->directory.'/provider-key.json'));
        } finally {
            new Filesystem()->remove($target);
        }
    }

    public function testKeepingASecondKeyIsRefusedAndTheFirstStays(): void
    {
        $file = $this->file();
        $file->keep($this->key('sk-ant-'.self::BODY));

        try {
            $file->keep($this->key('hf_'.self::BODY.'9999'));
            self::fail('The second key should be refused.');
        } catch (ProviderKeyAlreadyKept $alreadyKept) {
            self::assertSame('sk-ant-'.self::BODY, $file->read()?->secret());
            self::assertStringNotContainsString(self::BODY, print_r($alreadyKept->getTrace(), true).$alreadyKept);
        }
    }

    public function testARefusedSecondKeyLeavesNoNewFileInTheDirectory(): void
    {
        $file = $this->file();
        $file->keep($this->key('sk-ant-'.self::BODY));

        try {
            $file->keep($this->key('hf_'.self::BODY.'9999'));
        } catch (ProviderKeyAlreadyKept) {
            self::assertSame(['provider-key.json'], array_values(array_diff((array) scandir($this->directory), ['.', '..'])));
        }
    }

    public function testAKeptKeyTooShortForItsFormIsUnreadableInsteadOfShownWhole(): void
    {
        new Filesystem()->dumpFile($this->directory.'/provider-key.json', '{"provider":"openai","key":"abc"}');

        $this->expectException(KeptProviderKeyUnreadable::class);

        $this->file()->configured();
    }

    public function testAKeptKeyWhoseFormNamesAnotherProviderIsUnreadable(): void
    {
        new Filesystem()->dumpFile($this->directory.'/provider-key.json', '{"provider":"anthropic","key":"hf_'.self::BODY.'"}');

        $this->expectException(KeptProviderKeyUnreadable::class);

        $this->file()->read();
    }

    public function testAKeyThatCannotBeWrittenThrowsWithNoTraceOfTheKey(): void
    {
        new Filesystem()->dumpFile($this->directory, 'a file where the directory belongs');

        try {
            $this->file()->keep($this->key('sk-ant-'.self::BODY));
            self::fail('The key should not be written.');
        } catch (KeptProviderKeyNotWritten $notWritten) {
            self::assertNull($notWritten->getPrevious());
            self::assertStringNotContainsString(self::BODY, print_r($notWritten->getTrace(), true).$notWritten);
        }
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/'.uniqid('kept-key-', true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    private function file(): KeptProviderKeyFile
    {
        return new KeptProviderKeyFile($this->directory, new SymfonyAiProviderCatalog());
    }

    private function key(string $key): ProviderKey
    {
        return ProviderKey::recognise($key, new SymfonyAiProviderCatalog());
    }
}
