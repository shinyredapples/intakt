<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Memory\Infrastructure\EmbeddingsNotConfigured;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(EmbeddingsNotConfigured::class)]
final class MemoryWiringTest extends KernelTestCase
{
    public function testWithoutAnOpenAiAccountIndexingFailsNamingTheMissingEmbeddingModel(): void
    {
        $index = self::getContainer()->get(MemoryIndex::class);
        assert($index instanceof MemoryIndex);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/needs an embedding model/');

        $index->index(new MemoryEntry('Text.', ['src/A.php']));
    }
}
