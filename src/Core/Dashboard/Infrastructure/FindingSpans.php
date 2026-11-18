<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\FindingText;
use Twig\Attribute\AsTwigFunction;

final readonly class FindingSpans
{
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
    #[AsTwigFunction('cut_title_spans')]
    public function ofACutTitle(string $title): array
    {
        return new FindingText($title)->spansOfACutTitle();
    }
}
