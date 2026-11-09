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
use Neos\Neos\Domain\Service\ContentContext;
use PHPUnit\Framework\Attributes\Test;
use Shel\Neos\WorkspaceModule\Service\PreviewDocumentService;

/**
 * The tests of the resolution of the node of a preview.
 *
 * The preview is rendered on the host the review is opened on. The node of it is resolved in
 * the site it belongs to - the site is taken from the path of the node, not from the host of
 * the request.
 */
class PreviewDocumentServiceTest extends WorkspaceTestCase
{
    private PreviewDocumentService $previewDocumentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previewDocumentService = $this->getObject(PreviewDocumentService::class);
    }

    #[Test]
    public function theNodeOfThePreviewIsTheNodeOfThePath(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);

        $node = $this->previewDocumentService->resolveNode($home->getContextPath());

        self::assertInstanceOf(NodeInterface::class, $node);
        self::assertSame($home->getIdentifier(), $node->getIdentifier());
        self::assertSame('test', $node->getContext()->getWorkspaceName());
    }

    #[Test]
    public function theNodeOfThePreviewIsInTheSiteItBelongsTo(): void
    {
        // The site of the tests is reached on another host than the review - the node of the
        // preview is resolved in the site of its path nevertheless.
        $domain = $this->domainRepository->findOneByHostname($this->siteHostName);
        self::assertInstanceOf(Domain::class, $domain);
        $domain->setHostname('other-site.test');
        $this->domainRepository->update($domain);
        $this->persistenceManager->persistAll();

        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);

        $node = $this->previewDocumentService->resolveNode($home->getContextPath());

        self::assertInstanceOf(NodeInterface::class, $node);
        $context = $node->getContext();
        self::assertInstanceOf(ContentContext::class, $context);
        self::assertSame($this->siteNodeName, $context->getCurrentSite()->getNodeName());
        // The domain of the site is the one the links of the page point to.
        self::assertSame('other-site.test', $context->getCurrentDomain()->getHostname());
    }

    #[Test]
    public function aPathThatIsNoContextPathIsNoNode(): void
    {
        self::assertNull($this->previewDocumentService->resolveNode('sites/example/home'));
    }

    #[Test]
    public function aPathWithoutAWorkspaceIsTheNodeOfTheLiveWorkspace(): void
    {
        $liveHome = $this->nodeInLiveWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $liveHome);

        $node = $this->previewDocumentService->resolveNode($this->sitePath('/home'));

        self::assertInstanceOf(NodeInterface::class, $node);
        self::assertSame('live', $node->getContext()->getWorkspaceName());
        self::assertSame($liveHome->getIdentifier(), $node->getIdentifier());
    }

    #[Test]
    public function aPathOutsideOfASiteIsNoNode(): void
    {
        self::assertNull($this->previewDocumentService->resolveNode('/somewhere/else@test'));
    }

    #[Test]
    public function aPathThatPointsToNothingIsNoNode(): void
    {
        self::assertNull(
            $this->previewDocumentService->resolveNode($this->sitePath('/nothing-here') . '@test')
        );
    }
}
