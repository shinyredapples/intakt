<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\KeyNotRecognised;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnthropicDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\HuggingFaceDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\OpenAiDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProvider;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use Intakt\Plugin\Vendor\Contract\ProviderDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyAiProviderCatalog::class)]
#[CoversClass(AnthropicDescriptor::class)]
#[CoversClass(OpenAiDescriptor::class)]
#[CoversClass(HuggingFaceDescriptor::class)]
#[UsesClass(ProviderKey::class)]
#[UsesClass(KeyNotRecognised::class)]
#[UsesClass(Provider::class)]
final class SymfonyAiProviderCatalogTest extends TestCase
{
    private const string BODY = 'abcdefgh12345678';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function listedKeys(): iterable
    {
        yield 'anthropic' => ['sk-ant-'.self::BODY, 'anthropic'];
        yield 'openai' => ['sk-'.self::BODY, 'openai'];
        yield 'openai project' => ['sk-proj-'.self::BODY, 'openai'];
        yield 'hugging face' => ['hf_'.self::BODY, 'huggingface'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unlistedKeys(): iterable
    {
        yield 'unknown prefix' => ['xx-'.self::BODY];
        yield 'empty' => [''];
        yield 'anthropic prefix only' => ['sk-ant-'];
        yield 'openai prefix only' => ['sk-'];
        yield 'hugging face prefix only' => ['hf_'];
        yield 'cut-off anthropic key' => ['sk-ant-abcd'];
        yield 'anthropic key one short of its body' => ['sk-ant-'.str_repeat('0', 7)];
        yield 'hugging face token with a dash' => ['hf_'.str_repeat('0', 10).'-'.str_repeat('0', 10)];
        yield 'key with a newline inside' => ["sk-ant-abcd\nefgh1234"];
    }

    #[DataProvider('listedKeys')]
    public function testAKeyOfEachListedProviderIsRecognisedThroughTheCatalog(string $key, string $provider): void
    {
        self::assertSame($provider, ProviderKey::recognise($key, new SymfonyAiProviderCatalog())->provider());
    }

    #[DataProvider('unlistedKeys')]
    public function testAKeyOfNoListedFormIsRefused(string $key): void
    {
        $this->expectException(KeyNotRecognised::class);

        ProviderKey::recognise($key, new SymfonyAiProviderCatalog());
    }

    public function testABodyOfExactlyEightCharactersIsTheShortestKeyRecognised(): void
    {
        self::assertSame('anthropic', ProviderKey::recognise('sk-ant-'.str_repeat('0', 8), new SymfonyAiProviderCatalog())->provider());
    }

    public function testNoTwoListedFormsFitTheSameKey(): void
    {
        $descriptors = new SymfonyAiProviderCatalog()->descriptors();

        foreach (self::listedKeys() as $name => [$key]) {
            $fitting = array_filter($descriptors, static fn (ProviderDescriptor $descriptor): bool => 1 === preg_match($descriptor->keyPattern(), $key));

            self::assertCount(1, $fitting, $name);
        }
    }

    public function testADescriptorIsFoundByItsId(): void
    {
        self::assertInstanceOf(HuggingFaceDescriptor::class, new SymfonyAiProviderCatalog()->descriptor('huggingface'));
    }

    public function testAnUnlistedIdFindsNoDescriptor(): void
    {
        self::assertNotInstanceOf(SymfonyAiProvider::class, new SymfonyAiProviderCatalog()->descriptor('other'));
    }

    public function testEveryListedProviderHasItsOwnId(): void
    {
        $ids = array_map(static fn (ProviderDescriptor $descriptor): string => $descriptor->id(), new SymfonyAiProviderCatalog()->descriptors());

        self::assertSame(['anthropic', 'openai', 'huggingface'], $ids);
    }

    public function testNoListedProviderNamesAModelSoTheLightTierIsChecked(): void
    {
        $models = array_map(static fn (ProviderDescriptor $descriptor): ?string => $descriptor->checkModel(), new SymfonyAiProviderCatalog()->descriptors());

        self::assertSame([null, null, null], $models);
    }
}
