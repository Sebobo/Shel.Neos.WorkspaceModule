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
use Shel\Neos\WorkspaceModule\Service\NodeTreeService;

/**
 * The tests of the place of a node in the tree and in the workspace it is based on.
 */
class NodeTreeServiceTest extends WorkspaceTestCase
{
    private NodeTreeService $nodeTreeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nodeTreeService = $this->getObject(NodeTreeService::class);
    }

    #[Test]
    public function aNodeThatExistsInTheBaseWorkspaceIsNotNew(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);

        self::assertFalse($this->nodeTreeService->isNewInBaseWorkspace($home));
        self::assertFalse($this->nodeTreeService->isMovedInBaseWorkspace($home));
    }

    #[Test]
    public function aNodeThatWasCreatedInTheWorkspaceIsNew(): void
    {
        $site = $this->nodeInTestWorkspace('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(NodeInterface::class, $site);
        $newDocument = $site->createNode('new-page', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
        $this->persistenceManager->persistAll();

        self::assertTrue($this->nodeTreeService->isNewInBaseWorkspace($newDocument));
        self::assertSame('', $this->nodeTreeService->contextPathInBaseWorkspace($newDocument));
    }

    #[Test]
    public function thePathInTheBaseWorkspaceIsFound(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);

        // The context path carries the workspace and the dimensions of the node.
        self::assertStringStartsWith(
            $this->sitePath('/home') . '@live',
            $this->nodeTreeService->contextPathInBaseWorkspace($home)
        );
    }

    #[Test]
    public function theDocumentsADocumentSitsBelowAreFound(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $team = $home->getNode('team');
        self::assertInstanceOf(NodeInterface::class, $team);

        $ancestors = $this->nodeTreeService->documentAncestors($home);

        self::assertCount(0, $ancestors);
    }

    #[Test]
    public function theAncestorsOfAChangeAreFound(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $text = $home->getNode('main')->getNode('text');
        self::assertInstanceOf(NodeInterface::class, $text);

        $ancestors = $this->nodeTreeService->ancestorsOf($text, $home);

        // The collection of the document and the document itself.
        self::assertCount(2, $ancestors);
        self::assertSame('main', $ancestors[0]->getName());
        self::assertSame('home', $ancestors[1]->getName());
    }

    #[Test]
    public function theUnpublishedAncestorsOfAChangeAreFound(): void
    {
        $site = $this->nodeInTestWorkspace('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(NodeInterface::class, $site);
        $newDocument = $site->createNode('new-page', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
        $main = $newDocument->getNode('main');
        self::assertInstanceOf(NodeInterface::class, $main);
        $text = $main->createNode('text', $this->nodeTypeManager->getNodeType('Acme.Demo:Text'));
        $this->persistenceManager->persistAll();

        $unpublishedAncestors = $this->nodeTreeService->unpublishedAncestorsOf($text);

        // The collection and the document it is in were created in the workspace as well.
        self::assertCount(2, $unpublishedAncestors);
        self::assertSame('new-page', $unpublishedAncestors[1]->getName());
    }

    #[Test]
    public function theUnpublishedDescendantsOfANodeAreFound(): void
    {
        $site = $this->nodeInTestWorkspace('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(NodeInterface::class, $site);
        $newDocument = $site->createNode('new-page', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
        $main = $newDocument->getNode('main');
        self::assertInstanceOf(NodeInterface::class, $main);
        $main->createNode('text', $this->nodeTypeManager->getNodeType('Acme.Demo:Text'));
        $this->persistenceManager->persistAll();

        $descendants = $this->nodeTreeService->unpublishedDescendantsOf($newDocument);

        self::assertCount(2, $descendants);
        // The deepest node comes first, so that a node is never removed before its children.
        self::assertSame('text', $descendants[0]->getName());
        self::assertSame('main', $descendants[1]->getName());
    }
}
