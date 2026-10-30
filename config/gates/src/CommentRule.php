<?php

declare(strict_types=1);

namespace Intakt\Gates;

use PhpToken;

final readonly class CommentRule
{
    private const string MARKERS = '/\b(TODO|FIXME|XXX|HACK)\b/i';
    private const string GIVES_A_REASON = '~https?://\S+|\b(because|since|so|otherwise|unless|avoids?|keeps?|would|cannot|can\'t|must|needs?|requires?|upstream|workaround)\b~i';
    private const string LOOKS_LIKE_CODE = '/[;{}]\s*$/';

    public function __construct(private DocblockRule $docblocks = new DocblockRule())
    {
    }

    /**
     * @return list<string>
     */
    public function violations(string $source): array
    {
        $violations = [];
        $previousLine = null;

        foreach (PhpToken::tokenize($source) as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            $problem = $this->problemWith($token, $previousLine);
            $previousLine = T_COMMENT === $token->id ? $token->line : null;

            if (null !== $problem) {
                $violations[] = 'line '.$token->line.': '.$problem;
            }
        }

        return $violations;
    }

    private function problemWith(PhpToken $comment, ?int $previousLine): ?string
    {
        if (T_DOC_COMMENT === $comment->id) {
            return $this->docblocks->problem($comment->text);
        }

        return $this->lineCommentProblem($comment, $previousLine);
    }

    private function lineCommentProblem(PhpToken $comment, ?int $previousLine): ?string
    {
        $text = $comment->text;

        return match (true) {
            str_starts_with($text, '/*') => 'block comment',
            SuppressionDirective::appearsIn($text) => 'suppression directive, fix the code instead',
            1 === preg_match(self::MARKERS, $text) => 'TODO comment, open an issue instead',
            1 === preg_match(self::LOOKS_LIKE_CODE, $text) => 'commented-out code',
            1 !== preg_match(self::GIVES_A_REASON, $text) => 'comment that does not say why (a reason or a link); let names and small methods say what',
            null !== $previousLine && $comment->line === $previousLine + 1 => 'comment longer than one line',
            default => null,
        };
    }
}
