<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Contract;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedModel::class)]
final class ResolvedModelTest extends TestCase
{
    public function testAnotherPinnedProviderOfTheSameModelIsTheSameModel(): void
    {
        self::assertTrue(new ResolvedModel(Provider::OpenAiCompatible, 'owner/model:one')->isTheSameModelAs(new ResolvedModel(Provider::OpenAiCompatible, 'owner/model:two')));
    }

    public function testAnotherModelOnTheSameProviderIsNotTheSameModel(): void
    {
        self::assertFalse(new ResolvedModel(Provider::OpenAiCompatible, 'owner/model:one')->isTheSameModelAs(new ResolvedModel(Provider::OpenAiCompatible, 'owner/other:one')));
    }

    public function testTheSameNameOnAnotherProviderIsNotTheSameModel(): void
    {
        self::assertFalse(new ResolvedModel(Provider::OpenAi, 'model')->isTheSameModelAs(new ResolvedModel(Provider::OpenAiCompatible, 'model')));
    }

    public function testAModelWithoutAPinnedProviderIsTheSameModelAsItsPinnedForm(): void
    {
        self::assertTrue(new ResolvedModel(Provider::OpenAiCompatible, 'owner/model')->isTheSameModelAs(new ResolvedModel(Provider::OpenAiCompatible, 'owner/model:one')));
    }

    public function testOptionsDoNotMakeAPinnedAndAnUnpinnedFormTwoModels(): void
    {
        self::assertTrue(new ResolvedModel(Provider::OpenAiCompatible, 'owner/model:primary?temperature=0')->isTheSameModelAs(new ResolvedModel(Provider::OpenAiCompatible, 'owner/model?temperature=0')));
    }

    public function testAnUnpinnedModelWithOptionsIsTheSameModelAsItWithout(): void
    {
        self::assertTrue(new ResolvedModel(Provider::OpenAiCompatible, 'owner/model?temperature=0')->isTheSameModelAs(new ResolvedModel(Provider::OpenAiCompatible, 'owner/model')));
    }

    public function testTwoTagsOfAModelWithoutAnOwnerAreNotTheSameModel(): void
    {
        self::assertFalse(new ResolvedModel(Provider::OpenAiCompatible, 'llama3:8b')->isTheSameModelAs(new ResolvedModel(Provider::OpenAiCompatible, 'llama3:70b')));
    }

    public function testTwoFineTunedModelsOfOneBaseAreNotTheSameModel(): void
    {
        self::assertFalse(new ResolvedModel(Provider::OpenAi, 'ft:gpt-4o-mini:org::one')->isTheSameModelAs(new ResolvedModel(Provider::OpenAi, 'ft:gpt-4o-mini:org::two')));
    }
}
