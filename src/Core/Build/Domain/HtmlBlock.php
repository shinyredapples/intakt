<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// The seven CommonMark HTML block kinds, because the Evidence writer must read what each hides; kinds 1 to 5 end at their own end marker and kinds 6 and 7 at the next blank line.
enum HtmlBlock
{
    case Raw;
    case Comment;
    case Instruction;
    case Declaration;
    case CData;
    case Block;
    case Tag;
    private const string RAW_NAMES = 'pre|script|style|textarea';
    private const string BLOCK_NAMES = 'address|article|aside|base|basefont|blockquote|body|caption|center|col|colgroup|dd|details|dialog|dir|div|dl|dt|fieldset|figcaption|figure|footer|form|frame|frameset|h[1-6]|head|header|hr|html|iframe|legend|li|link|main|menu|menuitem|nav|noframes|ol|optgroup|option|p|param|search|section|summary|table|tbody|td|tfoot|th|thead|title|tr|track|ul';
    private const string ATTRIBUTE = '\s+[A-Za-z_:][A-Za-z0-9_.:-]*(?:\s*=\s*(?:[^\s"\'=<>`]+|\'[^\']*\'|"[^"]*"))?';

    public static function opening(string $line): ?self
    {
        foreach (self::cases() as $kind) {
            if (1 === preg_match($kind->start(), $line)) {
                return $kind;
            }
        }

        return null;
    }

    // The element a kind 1 line opens, because it stays open after the Markdown block ended on another end tag.
    public static function elementOpenedBy(string $line): ?string
    {
        return 1 === preg_match('/^ {0,3}<('.self::RAW_NAMES.')(?:[ \t>]|$)/i', $line, $match) ? strtolower($match[1]) : null;
    }

    public function endsAt(string $line): bool
    {
        $end = match ($this) {
            self::Raw => '/<\/(?:'.self::RAW_NAMES.')>/i',
            self::Comment => '/-->/',
            self::Instruction => '/\?>/',
            self::Declaration => '/>/',
            self::CData => '/\]\]>/',
            // A vertical tab or form feed keeps the block open, because CommonMark counts only spaces and tabs as blank.
            self::Block, self::Tag => '/^[ \t]*$/',
        };

        return 1 === preg_match($end, $line);
    }

    // Kinds 1, 6 and 7 have none, because the reading tracks a kind 1 element's own end tag and the results follow a blank line that ends kinds 6 and 7.
    public function closer(): ?string
    {
        return match ($this) {
            self::Raw, self::Block, self::Tag => null,
            self::Comment => '-->',
            self::Instruction => '?>',
            self::Declaration => '>',
            self::CData => ']]>',
        };
    }

    // Kinds 6 and 7 end at a blank line, which cannot be the line that opened them.
    public function endsOnOpeningLine(): bool
    {
        return !in_array($this, [self::Block, self::Tag], true);
    }

    private function start(): string
    {
        return match ($this) {
            self::Raw => '/^ {0,3}<(?:'.self::RAW_NAMES.')(?:[ \t>]|$)/i',
            self::Comment => '/^ {0,3}<!--/',
            self::Instruction => '/^ {0,3}<\?/',
            self::Declaration => '/^ {0,3}<![A-Za-z]/',
            self::CData => '/^ {0,3}<!\[CDATA\[/',
            self::Block => '/^ {0,3}<\/?(?:'.self::BLOCK_NAMES.')(?:[ \t]|\/?>|$)/i',
            self::Tag => '/^ {0,3}(?:<[A-Za-z][A-Za-z0-9-]*(?:'.self::ATTRIBUTE.')*\s*\/?>|<\/[A-Za-z][A-Za-z0-9-]*\s*>)[ \t]*$/',
        };
    }
}
