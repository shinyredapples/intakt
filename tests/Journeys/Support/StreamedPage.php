<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use DOMNode;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mercure\Update;

// Applies a round's recorded streams to a copy of the page a listener opened, as Turbo would, so a step reads what the reader sees without a reload.
final readonly class StreamedPage
{
    /**
     * @param list<Update> $updates
     */
    public function after(string $round, array $updates, Crawler $opened): Crawler
    {
        $topic = MercureRoundUpdates::topicOf($round);
        $recorded = array_values(array_filter($updates, static fn (Update $update): bool => in_array($topic, $update->getTopics(), true)));
        $page = new Crawler($opened->outerHtml());

        foreach ($recorded as $index => $update) {
            foreach (new Crawler($update->getData())->filter('body > turbo-stream')->each(static fn (Crawler $stream): Crawler => $stream) as $stream) {
                $this->apply($stream, $page, $index + 1);
            }
        }

        return $page;
    }

    private function apply(Crawler $stream, Crawler $page, int $position): void
    {
        $action = (string) $stream->attr('action');

        // A gone target is no failure here, because Turbo ignores it too and each reviewer's placement removes the line for no reviewer again.
        if ('remove' === $action) {
            $this->remove($this->matching($stream, $page));

            return;
        }

        foreach ($this->targetsOf($stream, $page, $position) as $target) {
            $content = $this->contentFor($stream, $target);

            match ($action) {
                'replace' => $this->replace($target, $content),
                'update' => $this->update($target, $content),
                'append' => $this->append($target, $content),
                default => throw new AssertionFailedError(sprintf('update %d has the action "%s", which the streamed page does not apply', $position, $action)),
            };
        }
    }

    /**
     * @return list<DOMNode>
     */
    private function matching(Crawler $stream, Crawler $page): array
    {
        $target = (string) $stream->attr('target');
        $elements = '' === $target ? $page->filter((string) $stream->attr('targets')) : $page->filterXPath('//*[@id='.Crawler::xpathLiteral($target).']');

        return iterator_to_array($elements, false);
    }

    /**
     * @param list<DOMNode> $targets
     */
    private function remove(array $targets): void
    {
        foreach ($targets as $target) {
            $target->parentNode?->removeChild($target);
        }
    }

    /**
     * @return list<DOMNode>
     */
    private function targetsOf(Crawler $stream, Crawler $page, int $position): array
    {
        $target = (string) $stream->attr('target');
        $found = $this->matching($stream, $page);

        // A targets selector may rightly match nothing, as Turbo then drops the stream, but content for a single target must find it on the page.
        if ('' === $target || [] !== $found) {
            return $found;
        }

        throw new AssertionFailedError(sprintf('update %d targets "%s", which names no element on the page', $position, $target));
    }

    /**
     * @return list<DOMNode>
     */
    private function contentFor(Crawler $stream, DOMNode $target): array
    {
        // Parsed inside a template, because only there a table row keeps its cells, and imported because a node belongs to the document it was parsed in.
        $template = new Crawler('<template>'.$stream->filter('template')->html('').'</template>')->filter('template')->getNode(0);
        $document = $target->ownerDocument ?? throw new AssertionFailedError('the streamed page has no document');

        return array_map(static fn (DOMNode $node): DOMNode => $document->importNode($node, true), iterator_to_array($template->childNodes ?? [], false));
    }

    /**
     * @param list<DOMNode> $content
     */
    private function replace(DOMNode $target, array $content): void
    {
        foreach ($content as $node) {
            $target->parentNode?->insertBefore($node, $target);
        }

        $target->parentNode?->removeChild($target);
    }

    /**
     * @param list<DOMNode> $content
     */
    private function update(DOMNode $target, array $content): void
    {
        while ($target->firstChild instanceof DOMNode) {
            $target->removeChild($target->firstChild);
        }

        $this->append($target, $content);
    }

    /**
     * @param list<DOMNode> $content
     */
    private function append(DOMNode $target, array $content): void
    {
        foreach ($content as $node) {
            $target->appendChild($node);
        }
    }
}
