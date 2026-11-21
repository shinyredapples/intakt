<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use Intakt\Core\Review\Infrastructure\EscalationPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(EscalationPrompt::class)]
#[UsesClass(DiffPaths::class)]
#[UsesClass(PromptCut::class)]
#[UsesClass(Fence::class)]
final class EscalationPromptTest extends TestCase
{
    private const string FINDING_SECTION = "diff --git a/src/Z.php b/src/Z.php\n@@ -3 +3 @@\n+the_relevant_change();\n";

    public function testTheFindingsHunkSurvivesTheCutAfterALongEarlierFile(): void
    {
        $request = $this->requestOn($this->longFile('src/A.php').self::FINDING_SECTION, 'src/Z.php');

        self::assertStringContainsString('+the_relevant_change();', $this->diffMessageOf($request));
    }

    public function testTheFindingsFileComesFirstAndTheOtherFilesFollowInTheirOrder(): void
    {
        $other = "diff --git a/src/A.php b/src/A.php\n@@ -1 +1 @@\n+other();\n";
        $request = $this->requestOn("diff --git a/src/B.php b/src/B.php\n+b();\n".$other.self::FINDING_SECTION, 'src/Z.php');

        $message = $this->diffMessageOf($request);

        self::assertSame([true, true], [strpos($message, 'the_relevant_change') < strpos($message, '+b();'), strpos($message, '+b();') < strpos($message, '+other();')]);
    }

    public function testAFindingThatNamesNoFileOfTheDiffKeepsTheDiffAsItIs(): void
    {
        $diff = "diff --git a/src/A.php b/src/A.php\n+a();\n".self::FINDING_SECTION;

        self::assertStringContainsString($diff, $this->diffMessageOf($this->requestOn($diff, 'src/Elsewhere.php')));
    }

    public function testAFindingFileThatFillsThePromptExactlyIsShownWhole(): void
    {
        $header = "diff --git a/src/Z.php b/src/Z.php\n";
        $request = $this->requestOn($header.str_repeat('+', PromptCut::CEILING_BYTES - strlen($header) - 1)."\n".$this->longFile('src/A.php'), 'src/Z.php');

        self::assertTrue($this->prompt()->showsTheFindingWhole($request));
    }

    public function testAFindingFileOneByteLongerThanThePromptIsNotShownWhole(): void
    {
        $header = "diff --git a/src/Z.php b/src/Z.php\n";
        $request = $this->requestOn($header.str_repeat('+', PromptCut::CEILING_BYTES - strlen($header))."\n", 'src/Z.php');

        self::assertFalse($this->prompt()->showsTheFindingWhole($request));
    }

    private function longFile(string $path): string
    {
        return 'diff --git a/'.$path.' b/'.$path."\n".str_repeat("+other change\n", 10000);
    }

    private function requestOn(string $diff, string $file): EscalationRequest
    {
        $finding = new Finding('F1', Severity::P1, $file, 3, null, 'The bus drops the message.', FixableBy::Builder, true, 'Messages must arrive.', null);

        return new EscalationRequest(new ChangeUnderReview('alpha', 'Deliver the messages.', $diff), $finding, Uuid::v7());
    }

    private function diffMessageOf(EscalationRequest $request): string
    {
        $text = $this->prompt()->messagesFor($request)->getMessages()[2]->asText();
        self::assertIsString($text);

        return $text;
    }

    private function prompt(): EscalationPrompt
    {
        return new EscalationPrompt(new PromptCut(new Fence()));
    }
}
