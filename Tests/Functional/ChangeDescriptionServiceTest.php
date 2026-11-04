<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Tests\Functional;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use PHPUnit\Framework\Attributes\Test;
use Shel\Neos\WorkspaceModule\Service\ChangeDescriptionService;
use Shel\Neos\WorkspaceModule\Service\NodeTreeService;
use Shel\Neos\WorkspaceModule\ViewModel\ContentChanges\TextContentChange;

/**
 * The tests of the description of a change: its kind and the difference of its properties.
 */
class ChangeDescriptionServiceTest extends WorkspaceTestCase
{
    private ChangeDescriptionService $changeDescriptionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->changeDescriptionService = $this->getObject(ChangeDescriptionService::class);
    }

    #[Test]
    public function aChangedPropertyMakesTheChangeAChangedOne(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $home->setProperty('title', 'A new title');
        $this->persistenceManager->persistAll();

        $change = $this->changeDescriptionService->describeChange($home);

        self::assertSame('changed', $change->type);
        self::assertSame('A new title', $change->label);
        self::assertSame('Document', $change->nodeTypeLabel);
    }

    #[Test]
    public function aNodeThatWasCreatedInTheWorkspaceIsACreatedChange(): void
    {
        $site = $this->nodeInTestWorkspace('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(NodeInterface::class, $site);
        $newDocument = $site->createNode('new-page', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
        $this->persistenceManager->persistAll();

        $change = $this->changeDescriptionService->describeChange($newDocument);

        self::assertSame('created', $change->type);
    }

    #[Test]
    public function aNodeThatWasDeletedInTheWorkspaceIsADeletedChange(): void
    {
        $aboutUs = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $aboutUs);
        $aboutUs->remove();
        $this->persistenceManager->persistAll();

        $change = $this->changeDescriptionService->describeChange($aboutUs);

        self::assertSame('deleted', $change->type);
    }

    #[Test]
    public function theDifferenceOfAChangedTextIsRendered(): void
    {
        $text = $this->textOfHomePage();
        $text->setProperty('text', 'A completely different text.');
        $this->persistenceManager->persistAll();

        $change = $this->changeDescriptionService->describeChange($text);

        $contentChanges = $change->contentChanges->items;
        self::assertArrayHasKey('text', $contentChanges);
        $textChange = $contentChanges['text']->changes;
        self::assertInstanceOf(TextContentChange::class, $textChange);
        self::assertNotSame([], $textChange->diff);
        self::assertSame('text', $contentChanges['text']->properties->type);
    }

    #[Test]
    public function aChangedReferenceIsNamedWithTheLabelOfTheNode(): void
    {
        $related = $this->nodeInTestWorkspace($this->sitePath('/home/related'));
        self::assertInstanceOf(NodeInterface::class, $related);
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        // The reference points to the home page now, before it pointed to the about us page.
        $related->setProperty('reference', $home->getIdentifier());
        $this->persistenceManager->persistAll();

        $change = $this->changeDescriptionService->describeChange($related);

        $contentChanges = $change->contentChanges->items;
        self::assertArrayHasKey('reference', $contentChanges);
        $textChange = $contentChanges['reference']->changes;
        self::assertInstanceOf(TextContentChange::class, $textChange);
        // The identifier alone does not tell which node it is - the label does.
        $diff = $this->textOfTheDiff($textChange->diff);
        self::assertStringContainsString('About us', $diff);
        self::assertStringContainsString('Home', $diff);
        self::assertStringContainsString($home->getIdentifier(), $diff);
    }

    #[Test]
    public function aChangedLinkToANodeIsNamedWithTheLabelOfTheNode(): void
    {
        $related = $this->nodeInTestWorkspace($this->sitePath('/home/related'));
        self::assertInstanceOf(NodeInterface::class, $related);
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        // The link points to the home page now, before it pointed to the about us page.
        $related->setProperty('link', 'node://' . $home->getIdentifier());
        $this->persistenceManager->persistAll();

        $change = $this->changeDescriptionService->describeChange($related);

        $contentChanges = $change->contentChanges->items;
        self::assertArrayHasKey('link', $contentChanges);
        $textChange = $contentChanges['link']->changes;
        self::assertInstanceOf(TextContentChange::class, $textChange);
        $diff = $this->textOfTheDiff($textChange->diff);
        self::assertStringContainsString('About us', $diff);
        self::assertStringContainsString('Home', $diff);
    }

    #[Test]
    public function aReferenceThatDidNotChangeIsNotInTheDifference(): void
    {
        $related = $this->nodeInTestWorkspace($this->sitePath('/home/related'));
        self::assertInstanceOf(NodeInterface::class, $related);
        $related->setProperty('title', 'A new title');
        $this->persistenceManager->persistAll();

        $change = $this->changeDescriptionService->describeChange($related);

        self::assertArrayNotHasKey('reference', $change->contentChanges->items);
    }

    #[Test]
    public function aPropertyThatDidNotChangeIsNotInTheDifference(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $home->setProperty('title', 'A new title');
        $this->persistenceManager->persistAll();

        $change = $this->changeDescriptionService->describeChange($home);

        self::assertArrayHasKey('title', $change->contentChanges->items);
        self::assertArrayNotHasKey('text', $change->contentChanges->items);
    }

    #[Test]
    public function theContainersOfAChangeAreNamed(): void
    {
        $text = $this->textOfHomePage();
        $text->setProperty('text', 'A completely different text.');
        $this->persistenceManager->persistAll();

        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $nodeTreeService = $this->getObject(NodeTreeService::class);
        $ancestors = $nodeTreeService->ancestorsOf($text, $home);

        $change = $this->changeDescriptionService->describeChange($text, $ancestors);

        self::assertNotSame([], $change->ancestors);
        // The label of the collection is translated - the name of the node is not.
        self::assertStringContainsString('main', $change->ancestors[0]->label);
    }

    private function textOfHomePage(): NodeInterface
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $text = $home->getNode('main')->getNode('text');
        self::assertInstanceOf(NodeInterface::class, $text);

        return $text;
    }

    /**
     * The text of a difference, without the marks that show what changed.
     *
     * The marks are in the middle of the text: a word that did not change is not marked,
     * and it is not part of the marked words either. The text of the difference is what the
     * reviewer sees.
     *
     * @param list<list<array<string, mixed>>> $diff
     */
    private function textOfTheDiff(array $diff): string
    {
        $text = '';
        foreach ($diff as $group) {
            foreach ($group as $block) {
                foreach (['base', 'changed'] as $side) {
                    foreach ($block[$side]['lines'] ?? [] as $line) {
                        $text .= strip_tags((string)$line) . "\n";
                    }
                }
            }
        }

        return $text;
    }
}
