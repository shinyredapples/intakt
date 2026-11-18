<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\FencedText;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\PromptPart;
use Intakt\Core\Review\Contract\UntrustedSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromptPart::class)]
#[CoversClass(PromptAssembly::class)]
#[UsesClass(FencedText::class)]
final class PromptPartTest extends TestCase
{
    public function testAPartWithoutABodyIsItsTextAlone(): void
    {
        self::assertSame('Build the task.', new PromptPart('Build the task.')->rendered());
    }

    public function testAPartWithABodyIsTheLeadABlankLineAndTheFencedText(): void
    {
        $fenced = new FencedText(UntrustedSource::Task, 'Add a cart.');

        self::assertSame(new PromptAssembly()->userText('The task:', $fenced), new PromptPart('The task:', $fenced)->rendered());
    }

    public function testADocumentJoinsItsPartsWithOneBlankLine(): void
    {
        $fenced = new FencedText(UntrustedSource::Task, 'Add a cart.');

        $text = new PromptAssembly()->document(new PromptPart('Build.'), new PromptPart('The task:', $fenced), new PromptPart('Write PR.md.'));

        self::assertSame("Build.\n\nThe task:\n\n".$fenced->rendered()."\n\nWrite PR.md.", $text);
    }
}
