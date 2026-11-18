<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\AddedLines;
use Intakt\Gates\CommentLanguages;
use Intakt\Gates\LeftUndoneCode;
use Intakt\Gates\LeftUndoneComments;
use Intakt\Gates\LeftUndoneGate;
use Intakt\Gates\LeftUndoneTokens;
use Intakt\Gates\PhpComments;
use Intakt\Gates\Scope;
use Intakt\Gates\TwigComments;
use Intakt\Gates\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(LeftUndoneGate::class)]
#[CoversClass(CommentLanguages::class)]
#[CoversClass(TwigComments::class)]
#[CoversClass(PhpComments::class)]
#[CoversClass(LeftUndoneComments::class)]
#[UsesClass(AddedLines::class)]
#[UsesClass(LeftUndoneCode::class)]
#[UsesClass(LeftUndoneTokens::class)]
#[UsesClass(Workspace::class)]
#[UsesClass(Scope::class)]
final class LeftUndoneGateTwigTest extends SandboxTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addedTwigMarkerComments(): iterable
    {
        yield 'a TODO comment' => ['{# TODO: show the total #}', 'TODO'];
        yield 'a FIXME comment' => ['{# FIXME the total is off by one #}', 'FIXME'];
        yield 'an XXX comment' => ['{# XXX breaks on empty orders #}', 'XXX'];
        yield 'a comment trimming whitespace' => ['{#- TODO show the total -#}', 'TODO'];
        yield 'an inline comment' => ['{{ order.total # TODO round it', 'TODO'];
    }

    #[DataProvider('addedTwigMarkerComments')]
    public function testAnAddedMarkerInATwigCommentFailsNamingTheFileTheLineAndHowToComply(string $comment, string $marker): void
    {
        $sandbox = $this->changeWithFile('templates/order.html.twig', $this->templateWithLine($comment));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->failed('left-undone'), $run->output());
        self::assertStringContainsString('templates/order.html.twig:3: '.$marker.' comment left in the change; finish the work, or open an issue and write TODO(#<issue>): <reason>', $run->findingsOf('left-undone'));
    }

    public function testAMarkerOnTheSecondLineOfAMultiLineTwigCommentIsNamedByItsOwnLine(): void
    {
        $sandbox = $this->changeWithFile('templates/order.html.twig', $this->templateWithLine("{#\n    FIXME the total\n#}"));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('templates/order.html.twig:4: FIXME comment', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function twigLinesWithoutALeftUndoneComment(): iterable
    {
        yield 'a marker in a string' => ["{{ 'TODO: show the total' }}"];
        yield 'a comment in a string' => ["{{ '{# TODO show the total #}' }}"];
        yield 'a marker in plain text' => ['TODO: show the total'];
        yield 'a marker in an HTML comment' => ['<!-- TODO show the total -->'];
        yield 'a reasoned exception' => ['{# TODO(#123): show the total once the forge sends it #}'];
        yield 'a not-implemented throw in plain text' => ["throw new LogicException('Not implemented');"];
    }

    #[DataProvider('twigLinesWithoutALeftUndoneComment')]
    public function testATwigLineWithoutALeftUndoneCommentPasses(string $line): void
    {
        $sandbox = $this->changeWithFile('templates/order.html.twig', $this->templateWithLine($line));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testATwigMarkerCommentThatExistedBeforeTheChangeIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('templates/order.html.twig', $this->templateWithLine('{# TODO: show the total #}'));
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');
        $sandbox->write('templates/order.html.twig', $this->templateWithLine("{# TODO: show the total #}\n<p>{{ order.count }}</p>"));
        $sandbox->commitAll('feat: show the count');

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testATwigMarkerCommentInAFileNotYetCommittedFails(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('templates/order.html.twig', $this->templateWithLine('{# TODO: show the total #}'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertStringContainsString('templates/order.html.twig:3: TODO comment', $run->findingsOf('left-undone'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function twigPathsThatAreNotScanned(): iterable
    {
        yield 'a test fixture' => ['tests/Fixtures/order.html.twig'];
        yield 'a gate fixture' => ['tests/Gates/Fixtures/clean/templates/order.html.twig'];
        yield 'a top-level fixture' => ['fixtures/sample-repository/templates/order.html.twig'];
        yield 'a vendored template' => ['vendor/acme/shop/templates/order.html.twig'];
        yield 'a file named like a template' => ['templates/order.twig.dist'];
    }

    #[DataProvider('twigPathsThatAreNotScanned')]
    public function testATwigMarkerCommentOutsideTheScopeIsNotScanned(string $path): void
    {
        $sandbox = $this->changeWithFile($path, $this->templateWithLine('{# TODO: show the total #}'));

        $run = $sandbox->runGate(new LeftUndoneGate());

        self::assertTrue($run->passed('left-undone'), $run->output());
    }

    public function testAPhpMarkerCommentIsStillReportedBesideATwigOne(): void
    {
        $sandbox = $this->startedChange();
        $sandbox->write('templates/order.html.twig', $this->templateWithLine('{# TODO: show the total #}'));
        $sandbox->write('src/Order.php', "<?php\n\n// FIXME the total\nfinal class Order {}\n");
        $sandbox->commitAll('feat: add the order');

        $findings = $sandbox->runGate(new LeftUndoneGate())->findingsOf('left-undone');

        self::assertStringContainsString('templates/order.html.twig:3: TODO comment', $findings);
        self::assertStringContainsString('src/Order.php:3: FIXME comment', $findings);
    }

    private function templateWithLine(string $line): string
    {
        return "{% extends 'base.html.twig' %}\n\n".$line."\n";
    }

    private function changeWithFile(string $path, string $contents): Sandbox
    {
        $sandbox = $this->startedChange();
        $sandbox->write($path, $contents);
        $sandbox->commitAll('feat: add a file');

        return $sandbox;
    }

    private function startedChange(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "# Shop\n");
        $sandbox->commitAll('chore: start');
        $sandbox->git('switch', '--quiet', '--create', 'change');

        return $sandbox;
    }
}
