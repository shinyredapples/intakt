<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FrontPageLandmarksTest extends TestCase
{
    private const string FRONT_PAGE = __DIR__.'/../../docs/pages/index.html';

    public function testTheSkipLinkTargetsTheOnlyMainLandmark(): void
    {
        $page = $this->frontPage();

        $mains = $page->querySelectorAll('main');
        $skip = $this->element($page, 'a.skip');

        self::assertCount(1, $mains);
        self::assertSame('#'.$this->element($page, 'main')->id, $skip->getAttribute('href'));
    }

    public function testTheMainLandmarkHoldsTheHeroHeadingThePitchAndBothButtons(): void
    {
        $page = $this->frontPage();

        $main = $this->element($page, 'main');
        $buttons = $page->querySelectorAll('.show .acts a');

        self::assertTrue($main->contains($this->element($page, 'h1')), 'the hero heading');
        self::assertTrue($main->contains($this->element($page, '.show .pitch')), 'the pitch');
        self::assertCount(2, $buttons);
        foreach ($buttons as $button) {
            self::assertTrue($main->contains($button), (string) $button->textContent);
        }
    }

    public function testTheLogoAndTheSiteNavigationComeBeforeTheMainLandmark(): void
    {
        $page = $this->frontPage();

        $main = $this->element($page, 'main');

        foreach (['a.logo', 'nav[aria-label="Site"]'] as $selector) {
            $outside = $this->element($page, $selector);
            self::assertFalse($main->contains($outside), $selector);
            self::assertSame(Node::DOCUMENT_POSITION_FOLLOWING, $outside->compareDocumentPosition($main) & Node::DOCUMENT_POSITION_FOLLOWING, $selector);
        }
    }

    private function frontPage(): HTMLDocument
    {
        return HTMLDocument::createFromFile(self::FRONT_PAGE);
    }

    private function element(HTMLDocument $page, string $selector): Element
    {
        $element = $page->querySelector($selector);
        self::assertNotNull($element, $selector);

        return $element;
    }
}
