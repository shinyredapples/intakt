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
}
