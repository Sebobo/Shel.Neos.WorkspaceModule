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
use Neos\Neos\Domain\Model\Domain;
use PHPUnit\Framework\Attributes\Test;
use Shel\Neos\WorkspaceModule\Service\DocumentDescriptionService;

/**
 * The tests of the description of a document: its kind, its changes and its place in the tree.
 */
class DocumentDescriptionServiceTest extends WorkspaceTestCase
{
    private DocumentDescriptionService $documentDescriptionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->documentDescriptionService = $this->getObject(DocumentDescriptionService::class);
    }

    #[Test]
    public function aDocumentThatWasChangedIsAnEditedOne(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $home->setProperty('title', 'A new title');
        $this->persistenceManager->persistAll();

        $document = $this->documentDescriptionService->describeDocument($home, [$home]);

        self::assertSame('edited', $document->kind);
        self::assertFalse($document->isNew);
        self::assertFalse($document->isDeleted);
        self::assertSame('A new title', $document->label);
    }

    #[Test]
    public function aDocumentThatWasCreatedIsACreatedOne(): void
    {
        $site = $this->nodeInTestWorkspace('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(NodeInterface::class, $site);
        $newDocument = $site->createNode('new-page', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
        $this->persistenceManager->persistAll();

        $document = $this->documentDescriptionService->describeDocument($newDocument);

        self::assertSame('created', $document->kind);
        self::assertTrue($document->isNew);
    }

    #[Test]
    public function aDocumentThatWasDeletedIsADeletedOne(): void
    {
        $aboutUs = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $aboutUs);
        $aboutUs->remove();
        $this->persistenceManager->persistAll();

        $document = $this->documentDescriptionService->describeDocument($aboutUs);

        self::assertSame('deleted', $document->kind);
        self::assertTrue($document->isDeleted);
    }

    #[Test]
    public function aDocumentThatOnlyLostSomethingBelowItIsAnEditedOne(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $text = $home->getNode('main')->getNode('text');
        self::assertInstanceOf(NodeInterface::class, $text);
        $text->remove();
        $this->persistenceManager->persistAll();

        $document = $this->documentDescriptionService->describeDocument($home, [$text]);

        self::assertSame('edited', $document->kind);
    }

    #[Test]
    public function aDocumentThatIsVisibleIsAccessible(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);

        $document = $this->documentDescriptionService->describeDocument($home);

        self::assertTrue($document->isAccessible);
    }

    #[Test]
    public function thePreviewOfADocumentIsLoadedFromTheHostOfTheReview(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);

        $document = $this->documentDescriptionService->describeDocument($home);

        // The preview is rendered by the action of the module, on the host the review is
        // opened on - not on the domain of the site the document belongs to.
        self::assertSame(
            'http://localhost/neos/workspace-review/preview?node=' . rawurlencode($home->getContextPath()),
            $document->previewUrl
        );
    }

    #[Test]
    public function thePublishedVersionOfADocumentIsPreviewedOnTheHostOfTheReviewAsWell(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $liveHome = $this->nodeInLiveWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $liveHome);

        $document = $this->documentDescriptionService->describeDocument($home);

        self::assertSame(
            'http://localhost/neos/workspace-review/preview?node=' . rawurlencode($liveHome->getContextPath()),
            $document->previewBaseUrl
        );
    }

    #[Test]
    public function aDocumentOfASiteWithoutADomainIsPreviewedOnTheHostOfTheReview(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        // A site without a domain cannot be reached - but its documents are previewed on the
        // host of the review nevertheless, because the preview does not need the domain.
        $domain = $this->domainRepository->findOneByHostname($this->siteHostName);
        self::assertInstanceOf(Domain::class, $domain);
        $domain->setActive(false);
        $this->persistenceManager->persistAll();

        $document = $this->documentDescriptionService->describeDocument($home);

        self::assertTrue($document->isAccessible);
        self::assertSame(
            'http://localhost/neos/workspace-review/preview?node=' . rawurlencode($home->getContextPath()),
            $document->previewUrl
        );
    }

    #[Test]
    public function aHiddenDocumentIsNotAccessible(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        // A hidden document is not rendered by the frontend - the preview of it would show
        // the page that says that the page was not found.
        $home->setHidden(true);
        $this->persistenceManager->persistAll();

        $document = $this->documentDescriptionService->describeDocument($home);

        self::assertFalse($document->isAccessible);
    }

    #[Test]
    public function aDeletedDocumentIsNotAccessible(): void
    {
        $aboutUs = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $aboutUs);
        $aboutUs->remove();
        $this->persistenceManager->persistAll();

        $document = $this->documentDescriptionService->describeDocument($aboutUs);

        self::assertFalse($document->isAccessible);
    }

    #[Test]
    public function theChangesOfADocumentAreCountedByTheirKind(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $text = $home->getNode('main')->getNode('text');
        self::assertInstanceOf(NodeInterface::class, $text);
        $text->setProperty('text', 'A new text');
        $home->setProperty('title', 'A new title');
        $this->persistenceManager->persistAll();

        $document = $this->documentDescriptionService->describeDocument($home, [$home, $text]);

        // The document and the text below it are changed.
        self::assertSame(2, $document->pendingChanges->changed);
        self::assertSame(0, $document->pendingChanges->new);
        self::assertSame(0, $document->pendingChanges->removed);
        self::assertSame(2, $document->pendingChanges->total);
    }

    #[Test]
    public function theDocumentsADocumentSitsBelowAreNamed(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $home->setProperty('title', 'A new title');
        $this->persistenceManager->persistAll();

        $document = $this->documentDescriptionService->describeDocument($home);

        // The site is the only document above the home page.
        self::assertSame([], $document->breadcrumb);
        self::assertSame(0, $document->level);
        self::assertNull($document->parent);
        self::assertFalse($document->parentIsInList);
    }
}
