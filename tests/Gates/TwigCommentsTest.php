<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\LeftUndoneComments;
use Intakt\Gates\ScopeFailure;
use Intakt\Gates\TwigComments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Source;

#[CoversClass(TwigComments::class)]
#[CoversClass(LeftUndoneComments::class)]
#[UsesClass(ScopeFailure::class)]
final class TwigCommentsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function templatesWithAMarkerComment(): iterable
    {
        yield 'a comment' => ["<p>\n{# TODO: show the total #}\n</p>\n", [2 => 'TODO']];
        yield 'a comment without spaces' => ["{#FIXME the total#}\n", [1 => 'FIXME']];
        yield 'a comment trimming whitespace' => ["{#- XXX breaks on empty orders -#}\n", [1 => 'XXX']];
        yield 'a comment trimming line whitespace' => ["{#~ TODO show the total ~#}\n", [1 => 'TODO']];
        yield 'a documentation comment' => ["{## TODO describe the order ##}\n", [1 => 'TODO']];
        yield 'a documentation comment trimming whitespace' => ["{##- TODO describe the order -##}\n", [1 => 'TODO']];
        yield 'the second line of a multi-line comment' => ["{#\n  TODO show the total\n#}\n", [2 => 'TODO']];
        yield 'a starred line of a multi-line comment' => ["{#\n * The order\n * FIXME the total\n #}\n", [3 => 'FIXME']];
        yield 'an inline comment in an output tag' => ["{{ order.total # TODO round it\n}}\n", [1 => 'TODO']];
        yield 'an inline comment behind a star and a space' => ["{{ total # * TODO round it\n}}\n", [1 => 'TODO']];
        yield 'a one-line comment behind a star and a space' => ["{# * TODO show the total #}\n", [1 => 'TODO']];
        yield 'an inline comment in a block tag' => ["{% set total = 3 # FIXME read the total\n%}\n", [1 => 'FIXME']];
        yield 'a comment after a string holding a comment end' => ["{{ '#}' }}{# TODO show the total #}\n", [1 => 'TODO']];
        yield 'a comment after nested brackets' => ["{{ {a: {b: 1}}|length }}{# TODO show the total #}\n", [1 => 'TODO']];
        yield 'a comment after an interpolated string' => ["{{ \"a #{ \"}}\" } b\" }}\n{# XXX later #}\n", [2 => 'XXX']];
        yield 'a comment after a verbatim block' => ["{% verbatim %}{{ x }}{% endverbatim %}\n{# TODO later #}\n", [2 => 'TODO']];
        yield 'a comment after carriage return line ends' => ["<p>\r\n\r\n{# TODO later #}\r\n", [3 => 'TODO']];
        yield 'a comment after a lone carriage return' => ["<p>\r{# TODO later #}\n", [1 => 'TODO']];
        yield 'an unclosed comment' => ["<p>\n{# TODO later\n", [2 => 'TODO']];
        yield 'a lower-case marker' => ["{# todo: later #}\n", [1 => 'todo']];
    }

    /**
     * @param array<int, string> $markers
     */
    #[DataProvider('templatesWithAMarkerComment')]
    public function testAMarkerInATwigCommentIsReportedOnItsGitLine(string $template, array $markers): void
    {
        self::assertSame($markers, $this->markersOn($template));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function templatesWithoutAMarkerComment(): iterable
    {
        yield 'a marker in a single-quoted string' => ["{{ 'TODO: show the total' }}\n"];
        yield 'a comment in a single-quoted string' => ["{{ '{# TODO #}' }}\n"];
        yield 'a comment in a double-quoted string' => ["{{ \"{# TODO #}\" }}\n"];
        yield 'an inline comment in a string' => ["{{ 'a # TODO b' }}\n"];
        yield 'a comment behind an escaped quote' => ["{{ 'it\\'s {# TODO #}' }}\n"];
        yield 'a comment in a string of a block tag' => ["{% set label = '{# FIXME #}' %}\n"];
        yield 'a comment in an interpolated string' => ["{{ \"a #{ '{# TODO #}' } b\" }}\n"];
        yield 'a hash in a double-quoted string' => ["{{ \"a # TODO b\" }}\n"];
        yield 'a marker in plain text' => ["<p>TODO: show the total</p>\n"];
        yield 'a marker in an HTML comment' => ["<!-- TODO show the total -->\n"];
        yield 'a comment in a verbatim block' => ["{% verbatim %}\n{# TODO #}\n{% endverbatim %}\n"];
        yield 'a comment in a trimmed verbatim block' => ["{%- verbatim -%}{# TODO #}{%~ endverbatim ~%}\n"];
        yield 'a marker after the comment start' => ["{# see the TODO list #}\n"];
        yield 'a reasoned exception' => ["{# TODO(#123): show the total once the forge sends it #}\n"];
        yield 'an empty comment' => ["{##}\n"];
        yield 'a marker behind PHP line comment syntax' => ["{# // TODO is the PHP syntax in this example #}\n"];
        yield 'a marker behind PHP block comment syntax' => ["{# /* FIXME */ is the PHP syntax #}\n"];
        yield 'a marker behind a hash in a comment' => ["{# # TODO is shell syntax #}\n"];
        yield 'a marker behind PHP syntax in an inline comment' => ["{{ order.total # // TODO is PHP syntax\n}}\n"];
        yield 'a marker in Markdown emphasis' => ["{## *TODO* is shown in italics in this documentation. ##}\n"];
        yield 'a marker in Markdown strong emphasis' => ["{# **FIXME** is shown in bold #}\n"];
        yield 'a marker in emphasis on a starred line' => ["{#\n * *XXX* is shown in italics\n #}\n"];
    }

    public function testAMarkerBehindAStarAndASpaceIsReportedWhileOneInEmphasisIsNot(): void
    {
        $template = "{## *TODO* is shown in italics ##}\n{# * TODO show the total #}\n{#\n *\n #}\n";

        self::assertSame([2 => 'TODO'], $this->markersOn($template));
    }

    public function testOnlyTheTwigCommentWhoseTextStartsWithAMarkerIsReported(): void
    {
        $template = "{# // TODO is the PHP syntax in this example #}\n{# FIXME this template #}\n";

        self::assertSame([2 => 'FIXME'], $this->markersOn($template));
    }

    #[DataProvider('templatesWithoutAMarkerComment')]
    public function testATwigTemplateWithoutAMarkerCommentHasNoMarkers(string $template): void
    {
        self::assertSame([], $this->markersOn($template));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function templatesTwigAccepts(): iterable
    {
        foreach (self::templatesWithAMarkerComment() as $name => [$template]) {
            if ('an unclosed comment' !== $name) {
                yield $name => [$template];
            }
        }

        yield from self::templatesWithoutAMarkerComment();
    }

    #[DataProvider('templatesTwigAccepts')]
    public function testEveryTemplateSampleExceptTheUnclosedCommentIsValidTwig(string $template): void
    {
        new Environment(new ArrayLoader())->tokenize(new Source($template, 'sample.twig'));

        $this->addToAssertionCount(1);
    }

    public function testAMarkerOnALineThatWasNotAddedIsNotReported(): void
    {
        $markers = new LeftUndoneComments()->markersOn("<p>\n{# TODO later #}\n", [1], new TwigComments());

        self::assertSame([], $markers);
    }

    public function testAMarkerInACommentTwigRejectsAsUnclosedIsStillReported(): void
    {
        $template = "{# TODO EOF\n";

        try {
            new Environment(new ArrayLoader())->tokenize(new Source($template, 'sample.twig'));
            self::fail('Twig accepted an unclosed comment.');
        } catch (SyntaxError $error) {
            self::assertStringStartsWith('Unclosed comment', $error->getMessage());
        }

        self::assertSame([1 => 'TODO'], new LeftUndoneComments()->markersOn($template, [1], new TwigComments()));
    }

    public function testATemplateThePatternEngineCannotReadFailsInsteadOfPassing(): void
    {
        $limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            new TwigComments()->commentsIn(str_repeat('{ ', 100)."{# TODO later #}\n");
            self::fail('The template passed although the pattern engine could not read it.');
        } catch (ScopeFailure $failure) {
            self::assertSame('cannot read a template: Backtrack limit exhausted', $failure->getMessage());
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }

    /**
     * @return array<int, string>
     */
    private function markersOn(string $template): array
    {
        return new LeftUndoneComments()->markersOn($template, range(1, substr_count($template, "\n") + 1), new TwigComments());
    }
}
