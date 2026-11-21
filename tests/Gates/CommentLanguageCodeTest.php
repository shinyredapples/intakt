<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\JavaScriptComments;
use Intakt\Gates\PhpComments;
use Intakt\Gates\TwigComments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpComments::class)]
#[CoversClass(JavaScriptComments::class)]
#[CoversClass(TwigComments::class)]
final class CommentLanguageCodeTest extends TestCase
{
    public function testAPhpFileHoldsPhpCode(): void
    {
        self::assertTrue(new PhpComments()->holdsPhpCode());
    }

    public function testAJavaScriptFileHoldsNoPhpCode(): void
    {
        self::assertFalse(new JavaScriptComments()->holdsPhpCode());
    }

    public function testATwigTemplateHoldsNoPhpCode(): void
    {
        self::assertFalse(new TwigComments()->holdsPhpCode());
    }
}
