<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\MarkdownPart;
use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\ParagraphEnd;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParagraphEnd::class)]
#[UsesClass(MarkdownText::class)]
#[UsesClass(MarkdownPart::class)]
final class ParagraphEndTest extends TestCase
{
    private const string FIRST_LINE = 'a `';

    #[TestWith([''], 'an empty line')]
    #[TestWith([" \t"], 'a line of blanks')]
    #[TestWith(['# b'], 'a heading')]
    #[TestWith(['###### b'], 'a heading of the lowest level')]
    #[TestWith(['#'], 'an empty heading')]
    #[TestWith(['    ## b'], 'a heading indented to the content of a list item')]
    #[TestWith(['==='], 'a setext underline of equals signs')]
    #[TestWith(['--'], 'a setext underline of dashes')]
    #[TestWith(['***'], 'a thematic break of asterisks')]
    #[TestWith(['_ _ _'], 'a thematic break with blanks')]
    #[TestWith(['```'], 'a backtick fence')]
    #[TestWith(['```php'], 'a backtick fence with a language')]
    #[TestWith(['~~~ a ` b'], 'a tilde fence with a backtick after it')]
    #[TestWith(['- b'], 'a bullet item')]
    #[TestWith(['+'], 'an empty bullet item')]
    #[TestWith(["*\tb"], 'a bullet item with a tab')]
    #[TestWith(['2) b'], 'an ordered item')]
    #[TestWith(['123456789. b'], 'an ordered item with the longest number')]
    #[TestWith(['<!-- b -->'], 'an HTML comment')]
    #[TestWith(['  <!--'], 'an indented HTML comment that runs on')]
    public function testAParagraphEndsBeforeALineThatStartsABlock(string $line): void
    {
        self::assertSame(strlen(self::FIRST_LINE), ParagraphEnd::after(self::FIRST_LINE."\n".$line."\nrest", strlen(self::FIRST_LINE)));
    }

    #[TestWith(['b'], 'plain text')]
    #[TestWith(['#b'], 'a hash without a blank after it')]
    #[TestWith(['####### b'], 'seven hashes')]
    #[TestWith(['= b'], 'an equals sign before text')]
    #[TestWith(['__'], 'two underscores')]
    #[TestWith(['**b**'], 'bold text')]
    #[TestWith(['``'], 'two backticks')]
    #[TestWith(['``` b `'], 'three backticks with a backtick after them')]
    #[TestWith(['-b'], 'a dash without a blank after it')]
    #[TestWith(['2)b'], 'a number without a blank after it')]
    #[TestWith(['1234567890. b'], 'a number of ten digits')]
    #[TestWith(['<!- b'], 'an opening angle bracket that is no comment')]
    #[TestWith(['<b>'], 'an inline HTML tag')]
    public function testAParagraphRunsOnOverALineThatStartsNoBlock(string $line): void
    {
        $text = self::FIRST_LINE."\n".$line;

        self::assertSame(strlen($text), ParagraphEnd::after($text, strlen(self::FIRST_LINE)));
    }

    #[TestWith(['## Heading `'])]
    #[TestWith(['  # Heading `'])]
    #[TestWith(['- ## Heading `'])]
    #[TestWith(["10.\t# Heading `"])]
    public function testAnAtxHeadingEndsWithItsOwnLine(string $heading): void
    {
        self::assertSame(strlen($heading), ParagraphEnd::after($heading."\nnext", strlen($heading)));
    }

    #[TestWith(['#Heading `'])]
    #[TestWith(['- text `'])]
    public function testALineThatIsNoHeadingRunsOnToTheNextLine(string $line): void
    {
        self::assertSame(strlen($line."\nnext"), ParagraphEnd::after($line."\nnext\n\nrest", strlen($line)));
    }

    public function testAParagraphWithoutAnEndRunsToTheEndOfTheText(): void
    {
        self::assertSame(5, ParagraphEnd::after("a\nb\nc", 0));
        self::assertSame(0, ParagraphEnd::after('', 0));
    }
}
