<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\FindingText;
use Twig\Attribute\AsTwigFunction;

final readonly class FindingSpans
{
    // In visible characters, so a finding written as one long line still reads as a title in the live log.
    private const int TITLE_LENGTH = 160;

    /**
     * @return list<array{text: string, code: bool}>
     */
    #[AsTwigFunction('finding_spans')]
    public function of(string $text): array
    {
        return new FindingText($text)->spans();
    }

    /**
     * @return list<array{text: string, code: bool}>
     */
    #[AsTwigFunction('finding_title_spans')]
    public function ofATitle(string $title): array
    {
        return new FindingText($title)->spansCutTo(self::TITLE_LENGTH);
    }
}
