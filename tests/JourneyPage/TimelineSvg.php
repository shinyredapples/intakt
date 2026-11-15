<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// Pages runs Jekyll, which does not render Mermaid, so the timeline is drawn here as one event per line.
final readonly class TimelineSvg
{
    private const float DOT = 62;
    private const float WHEN = 76;
    private const float EVENT = 150;
    private const float LINE = 18;

    public function __construct(private ChartCanvas $canvas = new ChartCanvas())
    {
    }

    public function svg(Timeline $timeline): string
    {
        $y = 32.0;
        $marks = '' === $timeline->title ? '' : $this->text(ChartCanvas::LEFT, $y, $timeline->title, 'font-size="16" font-weight="600" fill="'.ChartPalette::INK.'"');
        $y += 32;

        foreach ($timeline->sections as $section) {
            [$drawn, $y] = $this->section($section, $y);
            $marks .= $drawn;
        }

        return $this->canvas->opened('' === $timeline->title ? 'Timeline' : $timeline->title, $y).$marks.'</svg>';
    }

    /**
     * @return array{string, float}
     */
    private function section(TimelineSection $section, float $y): array
    {
        $marks = $this->text(ChartCanvas::LEFT, $y, $section->name, 'font-size="13" font-weight="600" fill="'.ChartPalette::MUTED.'"')
            .'<line x1="'.$this->canvas->number(ChartCanvas::LEFT).'" x2="'.$this->canvas->number(ChartCanvas::RIGHT).'" y1="'.$this->canvas->number($y + 8).'" y2="'.$this->canvas->number($y + 8).'" stroke="'.ChartPalette::GRID.'" stroke-width="1"/>';
        $y += 30;

        foreach ($section->periods as $period) {
            $marks .= '<circle cx="'.$this->canvas->number(self::DOT).'" cy="'.$this->canvas->number($y - 4).'" r="4" fill="'.ChartPalette::color(0).'"/>'
                .$this->text(self::WHEN, $y, $period->when, 'font-size="12" font-weight="600" fill="'.ChartPalette::INK.'"');

            foreach ($period->events as $event) {
                $marks .= $this->text(self::EVENT, $y, $event, 'font-size="12" fill="'.ChartPalette::INK.'"');
                $y += self::LINE;
            }

            $y += 8;
        }

        return [$marks, $y + 12];
    }

    private function text(float $x, float $y, string $text, string $style): string
    {
        return '<text x="'.$this->canvas->number($x).'" y="'.$this->canvas->number($y).'" '.$style.'>'.$this->canvas->text($text).'</text>';
    }
}
