<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use Intakt\Core\Memory\Contract\ProjectMemoryNeedsEmbeddingModel;
use Intakt\Core\Memory\Infrastructure\EmbeddingsNotConfigured;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingsNotConfigured::class)]
#[UsesClass(ProjectMemoryNeedsEmbeddingModel::class)]
final class EmbeddingsNotConfiguredTest extends TestCase
{
    public function testTheStandInPlatformRefusesWithTheMissingEmbeddingModelException(): void
    {
        $this->expectException(ProjectMemoryNeedsEmbeddingModel::class);

        new EmbeddingsNotConfigured()->invoke('text-embedding-3-small', 'text');
    }

    public function testTheStandInPlatformHasNoModelCatalog(): void
    {
        $this->expectException(ProjectMemoryNeedsEmbeddingModel::class);

        new EmbeddingsNotConfigured()->getModelCatalog();
    }
}
