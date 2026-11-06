<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CodingStandardsGate;
use Intakt\Gates\PhpCsFixer\MultilineNamedArgumentsFixer;
use PhpCsFixer\Tokenizer\Tokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use SplFileInfo;

#[CoversClass(MultilineNamedArgumentsFixer::class)]
#[UsesClass(CodingStandardsGate::class)]
final class MultilineNamedArgumentsFixerTest extends SandboxTestCase
{
    public function testACallWithOneNamedArgumentOnOneLineIsLeftAlone(): void
    {
        $code = <<<'PHP'
            <?php
            $report = render($issue, strict: true);

            PHP;

        self::assertSame($code, $this->fixed($code));
    }

    public function testACallWithTwoNamedArgumentsOnOneLineIsSplitWithATrailingComma(): void
    {
        $code = <<<'PHP'
            <?php
            function run(): void
            {
                $report = render(strict: true, limit: 3);
            }

            PHP;

        self::assertSame(<<<'PHP'
            <?php
            function run(): void
            {
                $report = render(
                    strict: true,
                    limit: 3,
                );
            }

            PHP, $this->fixed($code));
    }

    public function testAnAlreadySplitCallIsUnchanged(): void
    {
        $code = <<<'PHP'
            <?php
            $report = render(
                strict: true,
                limit: 3,
            );

            PHP;

        self::assertSame($code, $this->fixed($code));
    }

    public function testACallMixingPositionalAndNamedArgumentsIsSplitAsAWhole(): void
    {
        $code = <<<'PHP'
            <?php
            $report = render($issue, [1, 2], strict: true, limit: 3);

            PHP;

        self::assertSame(<<<'PHP'
            <?php
            $report = render(
                $issue,
                [1, 2],
                strict: true,
                limit: 3,
            );

            PHP, $this->fixed($code));
    }

    public function testAPartlySplitCallIsCompleted(): void
    {
        $code = <<<'PHP'
            <?php
            $report = render(strict: true,
                limit: 3);

            PHP;

        self::assertSame(<<<'PHP'
            <?php
            $report = render(
                strict: true,
                limit: 3,
            );

            PHP, $this->fixed($code));
    }

    public function testAClosingParenthesisAfterAMultilineBlockCommentMovesToItsOwnLine(): void
    {
        $code = "<?php\n\$report = render(strict: true, limit: 3, /* because explanation\nends here */);\n";

        self::assertSame("<?php\n\$report = render(\n    strict: true,\n    limit: 3, /* because explanation\nends here */\n);\n", $this->fixed($code));
    }

    public function testAOneLineCallThatAlreadyEndsInATrailingCommaGetsNoSecondComma(): void
    {
        self::assertSame("<?php\n\$report = render(\n    strict: true,\n    limit: 3,\n);\n", $this->fixed("<?php\n\$report = render(strict: true, limit: 3,);\n"));
    }

    public function testANestedCallWithTwoNamedArgumentsIsSplitAsWellAsItsOuterCall(): void
    {
        self::assertSame(
            "<?php\n\$report = render(\n    strict: format(\n    width: 80,\n    height: 3,\n),\n    limit: 3,\n);\n",
            $this->fixed("<?php\n\$report = render(strict: format(width: 80, height: 3), limit: 3);\n"),
        );
    }

    #[DataProvider('kindsOfCall')]
    public function testEveryKindOfCallWithTwoNamedArgumentsIsSplit(string $call): void
    {
        self::assertSame("<?php\n\$report = {$call}(\n    strict: true,\n    limit: 3,\n);\n", $this->fixed("<?php\n\$report = {$call}(strict: true, limit: 3);\n"));
    }

    /** @return iterable<string, array{string}> */
    public static function kindsOfCall(): iterable
    {
        yield 'function' => ['render'];
        yield 'method' => ['$renderer->render'];
        yield 'static method' => ['Renderer::render'];
        yield 'new' => ['new Report'];
    }

    public function testAnAttributeWithTwoNamedArgumentsIsUntouched(): void
    {
        $code = <<<'PHP'
            <?php
            #[Route(path: '/', name: 'home')]
            function home(): void
            {
            }

            PHP;

        self::assertSame($code, $this->fixed($code));
    }

    public function testATwoNamedArgumentCallFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'named-arguments-on-one-line', 'src/Report.php: Intakt/multiline_named_arguments');
    }

    private function fixed(string $code): string
    {
        Tokens::clearCache();
        $tokens = Tokens::fromCode($code);
        new MultilineNamedArgumentsFixer()->fix(new SplFileInfo('Report.php'), $tokens);

        return $tokens->generateCode();
    }
}
