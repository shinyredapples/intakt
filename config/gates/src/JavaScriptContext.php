<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because JavaScript reads a slash by the code before it, the scanner carries that code's context, changed in place so deep nesting stays linear.
final class JavaScriptContext
{
    // Because after these words an expression starts, so a slash there opens a regular expression, while after any other word it divides.
    private const array KEYWORDS_BEFORE_AN_EXPRESSION = ['return', 'typeof', 'instanceof', 'in', 'new', 'delete', 'void', 'throw', 'case', 'yield', 'await', 'extends'];
    // Because after these words a statement starts, a brace there opens a block and a function or class there is a declaration.
    private const array KEYWORDS_BEFORE_A_STATEMENT = ['do', 'else', 'try', 'finally', 'export', 'default'];
    // Because the parentheses after these words hold a condition or a head, a statement starts after them.
    private const array KEYWORDS_BEFORE_A_CONDITION = ['if', 'while', 'with', 'switch', 'catch'];

    private JavaScriptOpening $opening = JavaScriptOpening::Nothing;
    private JavaScriptForm $form = JavaScriptForm::Declaration;
    private bool $afterDot = false;
    private ?JavaScriptPosition $beforeAsync = null;
    private int $ternaries = 0;
    /** @var list<JavaScriptBracket> */
    private array $brackets = [];

    private function __construct(
        private JavaScriptPosition $position,
        private JavaScriptFrame $frame,
        private readonly JavaScriptCode $code,
    ) {
    }

    public static function forScript(): self
    {
        return new self(JavaScriptPosition::StatementStart, JavaScriptFrame::Statements, new JavaScriptCode());
    }

    public static function forTemplateExpression(): self
    {
        return new self(JavaScriptPosition::ExpressionStart, JavaScriptFrame::Expression, new JavaScriptCode());
    }

    public function slashStartsARegex(): bool
    {
        return JavaScriptPosition::AfterOperand !== $this->position;
    }

    public function isOutermost(): bool
    {
        return [] === $this->brackets;
    }

    public function read(string $code): void
    {
        foreach ($this->code->tokensIn($code) as $token) {
            $this->take($token);
        }
    }

    public function readValue(): void
    {
        $this->moveTo(JavaScriptPosition::AfterOperand);
    }

    public function readDivision(): void
    {
        $this->moveTo(JavaScriptPosition::ExpressionStart);
    }

    public function open(string $bracket): void
    {
        [$frame, $bracket] = '{' === $bracket ? $this->braceOpened() : $this->bracketOpened($bracket);
        $this->brackets[] = $bracket;
        $this->frame = $frame;
        $this->ternaries = 0;
        $this->opening = JavaScriptOpening::Nothing;
        $this->afterDot = false;
        $this->beforeAsync = null;
        $this->position = JavaScriptFrame::Statements === $frame ? JavaScriptPosition::StatementStart : JavaScriptPosition::ExpressionStart;
    }

    public function close(): void
    {
        $bracket = array_pop($this->brackets);

        if (null === $bracket) {
            $this->moveTo(JavaScriptPosition::AfterOperand);

            return;
        }

        $this->frame = $bracket->enclosing;
        $this->ternaries = $bracket->enclosingTernaries;
        $this->position = $bracket->after;
        $this->opening = $bracket->opensAfter;
        $this->form = $bracket->formAfter;
        $this->afterDot = false;
        $this->beforeAsync = null;
    }

    private function take(string $token): void
    {
        $isWord = ord($token[0]) >= 0x80 || ctype_alnum($token[0]) || str_contains('_$#', $token[0]);
        $afterDot = $this->afterDot;
        $beforeAsync = $this->beforeAsync;
        $this->afterDot = false;
        $this->beforeAsync = null;

        if (!$isWord) {
            $this->punctuator($token);
        } elseif ($afterDot || ctype_digit($token[0])) {
            $this->moveTo(JavaScriptPosition::AfterOperand);
        } else {
            $this->word($token, $beforeAsync);
        }
    }

    private function punctuator(string $token): void
    {
        $position = match ($token) {
            '++', '--' => JavaScriptPosition::AfterOperand === $this->position ? JavaScriptPosition::AfterOperand : JavaScriptPosition::ExpressionStart,
            ':' => $this->afterColon(),
            ';' => JavaScriptPosition::StatementStart,
            '?' => $this->afterQuestionMark(),
            default => JavaScriptPosition::ExpressionStart,
        };

        $this->afterDot = '.' === $token || '?.' === $token;

        if ('=>' === $token) {
            $this->expect(JavaScriptOpening::ArrowBody, JavaScriptForm::Declaration);
        } elseif ('*' !== $token || JavaScriptOpening::FunctionHead !== $this->opening) {
            $this->moveTo($position);
        }
    }

    private function word(string $word, ?JavaScriptPosition $beforeAsync): void
    {
        $before = $beforeAsync ?? $this->position;
        $form = JavaScriptPosition::StatementStart === $before ? JavaScriptForm::Declaration : JavaScriptForm::Expression;

        match (true) {
            JavaScriptOpening::FunctionHead === $this->opening => $this->position = JavaScriptPosition::AfterOperand,
            'function' === $word => $this->expect(JavaScriptOpening::FunctionHead, $form),
            'class' === $word => $this->expect(JavaScriptOpening::ClassBody, $form),
            'for' === $word, 'await' === $word && JavaScriptOpening::ForHead === $this->opening => $this->expect(JavaScriptOpening::ForHead, JavaScriptForm::Declaration),
            in_array($word, self::KEYWORDS_BEFORE_A_CONDITION, true) => $this->expect(JavaScriptOpening::Condition, JavaScriptForm::Declaration),
            in_array($word, self::KEYWORDS_BEFORE_A_STATEMENT, true) => $this->moveTo(JavaScriptPosition::StatementStart),
            in_array($word, self::KEYWORDS_BEFORE_AN_EXPRESSION, true), 'of' === $word && JavaScriptFrame::ForHead === $this->frame => $this->moveTo(JavaScriptPosition::ExpressionStart),
            default => $this->moveTo(JavaScriptPosition::AfterOperand),
        };

        // Because `async function` declares a function where `async` stood, the position before it decides.
        if ('async' === $word) {
            $this->beforeAsync = $before;
        }
    }

    private function afterColon(): JavaScriptPosition
    {
        if ($this->ternaries > 0) {
            --$this->ternaries;

            return JavaScriptPosition::ExpressionStart;
        }

        return JavaScriptFrame::Statements === $this->frame ? JavaScriptPosition::StatementStart : JavaScriptPosition::ExpressionStart;
    }

    private function afterQuestionMark(): JavaScriptPosition
    {
        ++$this->ternaries;

        return JavaScriptPosition::ExpressionStart;
    }

    private function expect(JavaScriptOpening $opening, JavaScriptForm $form): void
    {
        $this->position = JavaScriptPosition::ExpressionStart;
        $this->opening = $opening;
        $this->form = $form;
    }

    // Because a class heritage such as `extends mixin(a).b` may stand between `class` and its body, the class body stays expected until its brace.
    private function moveTo(JavaScriptPosition $position): void
    {
        $this->position = $position;

        if (JavaScriptOpening::ClassBody !== $this->opening) {
            $this->opening = JavaScriptOpening::Nothing;
        }
    }

    /**
     * @return array{JavaScriptFrame, JavaScriptBracket}
     */
    private function braceOpened(): array
    {
        $isBody = in_array($this->opening, [JavaScriptOpening::FunctionBody, JavaScriptOpening::ClassBody, JavaScriptOpening::ArrowBody], true);

        if (!$isBody && JavaScriptPosition::ExpressionStart === $this->position) {
            return [JavaScriptFrame::Expression, $this->bracket(JavaScriptPosition::AfterOperand, JavaScriptOpening::Nothing)];
        }

        $isValue = $isBody && JavaScriptForm::Expression === $this->form && JavaScriptOpening::ArrowBody !== $this->opening;

        return [JavaScriptFrame::Statements, $this->bracket($isValue ? JavaScriptPosition::AfterOperand : JavaScriptPosition::StatementStart, JavaScriptOpening::Nothing)];
    }

    /**
     * @return array{JavaScriptFrame, JavaScriptBracket}
     */
    private function bracketOpened(string $bracket): array
    {
        if ('[' === $bracket) {
            return [JavaScriptFrame::Expression, $this->bracket(JavaScriptPosition::AfterOperand, $this->classBodyOrNothing())];
        }

        return match ($this->opening) {
            JavaScriptOpening::FunctionHead => [JavaScriptFrame::Expression, $this->bracket(JavaScriptPosition::AfterOperand, JavaScriptOpening::FunctionBody)],
            JavaScriptOpening::Condition => [JavaScriptFrame::Expression, $this->bracket(JavaScriptPosition::StatementStart, JavaScriptOpening::Nothing)],
            JavaScriptOpening::ForHead => [JavaScriptFrame::ForHead, $this->bracket(JavaScriptPosition::StatementStart, JavaScriptOpening::Nothing)],
            default => [JavaScriptFrame::Expression, $this->bracket(JavaScriptPosition::AfterOperand, $this->classBodyOrNothing())],
        };
    }

    private function classBodyOrNothing(): JavaScriptOpening
    {
        return JavaScriptOpening::ClassBody === $this->opening ? JavaScriptOpening::ClassBody : JavaScriptOpening::Nothing;
    }

    private function bracket(JavaScriptPosition $after, JavaScriptOpening $opensAfter): JavaScriptBracket
    {
        return new JavaScriptBracket($this->frame, $this->ternaries, $after, $opensAfter, $this->form);
    }
}
