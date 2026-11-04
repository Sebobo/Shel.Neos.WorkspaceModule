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
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use PHPUnit\Framework\Attributes\Test;
use Shel\Neos\WorkspaceModule\Service\WorkspacePublishingService;

/**
 * The tests of the publishing and the discarding of the changes of a workspace.
 */
class WorkspacePublishingServiceTest extends WorkspaceTestCase
{
    private WorkspacePublishingService $workspacePublishingService;
    private NodeDataRepository $nodeDataRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspacePublishingService = $this->getObject(WorkspacePublishingService::class);
        $this->nodeDataRepository = $this->getObject(NodeDataRepository::class);
    }

    #[Test]
    public function aChangeIsPublishedInTheBaseWorkspace(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $home->setProperty('title', 'A new title');
        $this->persistenceManager->persistAll();

        $this->workspacePublishingService->publishNodesWithTheirAncestors([$home]);
        $this->persistenceManager->persistAll();

        $published = $this->nodeInLiveWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $published);
        self::assertSame('A new title', $published->getProperty('title'));
    }

    #[Test]
    public function aChangeThatNeedsItsParentIsPublishedWithIt(): void
    {
        $site = $this->nodeInTestWorkspace('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(NodeInterface::class, $site);
        $newDocument = $site->createNode('new-page', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
        $main = $newDocument->getNode('main');
        self::assertInstanceOf(NodeInterface::class, $main);
        $text = $main->createNode('text', $this->nodeTypeManager->getNodeType('Acme.Demo:Text'));
        $text->setProperty('text', 'The text of the new page.');
        $this->persistenceManager->persistAll();

        $this->workspacePublishingService->publishNodesWithTheirAncestors([$text]);
        $this->persistenceManager->persistAll();

        // The text is published, and so is the collection and the document it is in.
        $publishedText = $this->nodeInLiveWorkspace($this->sitePath('/new-page/main/text'));
        self::assertInstanceOf(NodeInterface::class, $publishedText);
        self::assertSame('The text of the new page.', $publishedText->getProperty('text'));

        $publishedDocument = $this->nodeInLiveWorkspace($this->sitePath('/new-page'));
        self::assertInstanceOf(NodeInterface::class, $publishedDocument);
    }

    #[Test]
    public function discardingANodeThatWasCreatedInTheWorkspaceRemovesItsChildren(): void
    {
        $site = $this->nodeInTestWorkspace('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(NodeInterface::class, $site);
        $newDocument = $site->createNode('new-page', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
        $main = $newDocument->getNode('main');
        self::assertInstanceOf(NodeInterface::class, $main);
        $main->createNode('text', $this->nodeTypeManager->getNodeType('Acme.Demo:Text'));
        $this->persistenceManager->persistAll();

        $this->workspacePublishingService->discardNodeWithItsChildren($newDocument);
        $this->persistenceManager->persistAll();

        // Neither the document nor its children are left behind - a child whose parent no
        // longer exists is an orphan that the content repository cannot do anything with.
        $pathsInTheWorkspace = [];
        foreach ($this->nodeDataRepository->findByWorkspace($this->testWorkspace()) as $nodeData) {
            $pathsInTheWorkspace[] = $nodeData->getPath();
        }

        self::assertNotContains($this->sitePath('/new-page'), $pathsInTheWorkspace);
        self::assertNotContains($this->sitePath('/new-page/main'), $pathsInTheWorkspace);
        self::assertNotContains($this->sitePath('/new-page/main/text'), $pathsInTheWorkspace);
    }

    #[Test]
    public function discardingANodeThatExistsInTheBaseWorkspaceKeepsItsChildren(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $home->setProperty('title', 'A new title');
        $this->persistenceManager->persistAll();

        $this->workspacePublishingService->discardNodeWithItsChildren($home);
        $this->persistenceManager->persistAll();
        // The repository of the nodes and the contexts hold the nodes of the workspace - they
        // have to forget them, so that the node is read again from the database.
        $this->nodeDataRepository->flushNodeRegistry();
        $this->contextFactory->reset();

        // The change is gone, the document is the published one again.
        $discarded = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $discarded);
        self::assertSame('Home', $discarded->getProperty('title'));

        $text = $discarded->getNode('main')->getNode('text');
        self::assertInstanceOf(NodeInterface::class, $text);
    }

    #[Test]
    public function publishingARemovedDocumentRemovesItFromTheBaseWorkspace(): void
    {
        $aboutUs = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $aboutUs);
        $aboutUs->remove();
        $this->persistenceManager->persistAll();

        $this->workspacePublishingService->publishNodesWithTheirAncestors([$aboutUs]);
        $this->persistenceManager->persistAll();
        $this->nodeDataRepository->flushNodeRegistry();
        $this->contextFactory->reset();

        // The document is not in the published workspace any more - a removed document is not
        // rendered by the frontend, and the review shows it as a deleted document.
        self::assertNull($this->nodeInLiveWorkspace($this->sitePath('/about-us')));

        // The document is still in the workspace under review - it is only gone in the
        // published one.
        $removed = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertNull($removed);
    }

    #[Test]
    public function discardingARemovedDocumentBringsItBack(): void
    {
        $aboutUs = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $aboutUs);
        $aboutUs->remove();
        $this->persistenceManager->persistAll();

        $this->workspacePublishingService->discardNodeWithItsChildren($aboutUs);
        $this->persistenceManager->persistAll();
        $this->nodeDataRepository->flushNodeRegistry();
        $this->contextFactory->reset();

        // The document is back - the change that removed it is gone.
        $restored = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $restored);
        self::assertSame('About us', $restored->getProperty('title'));

        // And it is untouched in the published workspace.
        $published = $this->nodeInLiveWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $published);
        self::assertSame('About us', $published->getProperty('title'));
    }
}
