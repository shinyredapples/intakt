<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The weekly share and the issue label of the Add project flow's fourth step, checked on the server because the page must say what is wrong without a script; nothing keeps them yet.
#[Exclude]
final readonly class ShareAndLabelField
{
    public const string SHARE_EMPTY = 'Enter the weekly share in percent.';
    public const string SHARE_OUT_OF_RANGE = 'The weekly share is a whole number from 0 to 100.';
    public const string LABEL_EMPTY = 'Enter the issue label.';
    public const string LABEL_TOO_LONG = 'A GitHub label has at most 50 characters.';
    public const string LABEL_NOT_ONE_LINE = 'A GitHub label is one line of text, without tabs or line breaks.';

    // The mock's starting position of the slider, so the step opens on a share instead of the slider's midpoint; nothing keeps it yet.
    public const string DEFAULT_SHARE = '20';

    // GitHub's own limit for a label's name, because a label GitHub would refuse should fail here and not at the first call to GitHub.
    private const int LABEL_LIMIT = 50;

    private function __construct(
        public string $share,
        public string $label,
        public ?string $shareError,
        public ?string $labelError,
        private bool $checked,
    ) {
    }

    public static function unsubmitted(string $defaultLabel): self
    {
        return new self(self::DEFAULT_SHARE, $defaultLabel, null, null, checked: false);
    }

    public static function submitted(string $share, string $label): self
    {
        return new self($share, $label, self::shareErrorOf(trim($share)), self::labelErrorOf(trim($label)), checked: true);
    }

    public function isAccepted(): bool
    {
        return $this->checked && null === $this->shareError && null === $this->labelError;
    }

    public function percent(): int
    {
        return (int) trim($this->share);
    }

    // In plain digits, because a range input drops a value with a sign or spaces and falls back to its midpoint; a refused share has no place on the slider, so it shows the default and the error names the problem.
    public function sliderShare(): string
    {
        return null === $this->shareError ? (string) $this->percent() : self::DEFAULT_SHARE;
    }

    private static function shareErrorOf(string $share): ?string
    {
        if ('' === $share) {
            return self::SHARE_EMPTY;
        }

        $percent = filter_var($share, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);

        return false === $percent ? self::SHARE_OUT_OF_RANGE : null;
    }

    private static function labelErrorOf(string $label): ?string
    {
        if ('' === $label) {
            return self::LABEL_EMPTY;
        }

        // A text that is not UTF-8 makes the match fail, and is refused like a control character, since GitHub refuses both. The Unicode line and paragraph separators break a line without being control characters.
        if (1 === preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $label) || false === preg_match('//u', $label)) {
            return self::LABEL_NOT_ONE_LINE;
        }

        return mb_strlen($label) > self::LABEL_LIMIT ? self::LABEL_TOO_LONG : null;
    }
}
