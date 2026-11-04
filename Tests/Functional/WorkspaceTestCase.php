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

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;

/**
 * The base of the tests of the review.
 *
 * The tests work with a content repository that holds a site with two documents: the
 * "live" workspace has the published version of them, the "test" workspace the changes that
 * the tests make.
 */
abstract class WorkspaceTestCase extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;

    protected ContextFactoryInterface $contextFactory;
    protected NodeTypeManager $nodeTypeManager;
    protected WorkspaceRepository $workspaceRepository;
    protected SiteRepository $siteRepository;

    protected Workspace $liveWorkspace;
    protected Workspace $testWorkspace;

    /**
     * The name of the site node - the default site of the installation, so that the
     * contexts of the framework find it.
     */
    protected string $siteNodeName = 'example';

    protected function setUp(): void
    {
        parent::setUp();

        $this->contextFactory = $this->getObject(ContextFactoryInterface::class);
        $this->nodeTypeManager = $this->getObject(NodeTypeManager::class);
        $this->workspaceRepository = $this->getObject(WorkspaceRepository::class);
        $this->siteRepository = $this->getObject(SiteRepository::class);
        $this->siteNodeName = $this->defaultSiteNodeName();

        $this->liveWorkspace = new Workspace('live');
        $this->testWorkspace = new Workspace('test', $this->liveWorkspace);
        $this->workspaceRepository->add($this->liveWorkspace);
        $this->workspaceRepository->add($this->testWorkspace);

        $site = new Site($this->siteNodeName);
        $site->setState(Site::STATE_ONLINE);
        $site->setSiteResourcesPackageKey('Neos.Fusion');
        $this->siteRepository->add($site);

        $this->persistenceManager->persistAll();

        $this->setUpContent();
    }

    /**
     * The content the tests work with.
     *
     * The hook exists for the tests that talk to the module over http: the content can only
     * be created when a user is authenticated, because the content repository asks the
     * privileges of the user before it writes.
     */
    protected function setUpContent(): void
    {
        $this->createContentInLiveWorkspace();
    }

    /**
     * An object of the object manager, with its type.
     *
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    protected function getObject(string $className): object
    {
        /** @var T $object */
        $object = $this->objectManager->get($className);

        return $object;
    }

    protected function tearDown(): void
    {
        $this->contextFactory->reset();
        parent::tearDown();
    }

    /**
     * The name of the site node the contexts of the framework use.
     */
    protected function defaultSiteNodeName(): string
    {
        $configurationManager = $this->getObject(ConfigurationManager::class);
        $settings = $configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS);

        $defaultSiteNodeName = $settings['Neos']['Neos']['defaultSiteNodeName'] ?? null;

        return is_string($defaultSiteNodeName) && $defaultSiteNodeName !== '' ? $defaultSiteNodeName : 'example';
    }

    /**
     * The site with two documents: "Home" and "About us", each of them with a text.
     *
     * Below the home page are three documents that share it as their parent - the home page
     * itself has no change of its own, so the documents below it are named by their
     * breadcrumb.
     */
    protected function createContentInLiveWorkspace(): void
    {
        $liveContext = $this->contextFactory->create(['workspaceName' => 'live']);
        $rootNode = $liveContext->getRootNode();

        $sitesNode = $rootNode->createNode('sites', $this->nodeTypeManager->getNodeType('Neos.Neos:Sites'));
        $siteNode = $sitesNode->createNode($this->siteNodeName, $this->nodeTypeManager->getNodeType('Neos.Neos:Site'));

        $home = $siteNode->createNode('home', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
        $home->setProperty('title', 'Home');
        $this->createTextIn($home, 'The text of the home page.');

        $aboutUs = $siteNode->createNode('about-us', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
        $aboutUs->setProperty('title', 'About us');
        $this->createTextIn($aboutUs, 'The text of the about us page.');

        // Three documents below the home page, so that the documents are nested.
        foreach (['team' => 'Team', 'services' => 'Services', 'contact' => 'Contact'] as $nodeName => $title) {
            $document = $home->createNode($nodeName, $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
            $document->setProperty('title', $title);
            $this->createTextIn($document, 'The text of the ' . $title . ' page.');
        }

        // A document that points to another one.
        $related = $home->createNode(
            'related',
            $this->nodeTypeManager->getNodeType('Shel.Neos.WorkspaceModule:Testing:PageWithReference')
        );
        $related->setProperty('title', 'Related');
        $related->setProperty('reference', $aboutUs->getIdentifier());
        $related->setProperty('link', 'node://' . $aboutUs->getIdentifier());

        $this->persistenceManager->persistAll();
    }

    /**
     * A text in the main collection of a document.
     */
    protected function createTextIn(NodeInterface $document, string $text): NodeInterface
    {
        $main = $document->getNode('main');
        $textNode = $main->createNode('text', $this->nodeTypeManager->getNodeType('Acme.Demo:Text'));
        $textNode->setProperty('text', $text);

        return $textNode;
    }

    /**
     * The path of a node in the site of the tests.
     */
    protected function sitePath(string $path): string
    {
        return '/sites/' . $this->siteNodeName . $path;
    }

    /**
     * The node of a path in the "test" workspace.
     */
    protected function nodeInTestWorkspace(string $path): ?NodeInterface
    {
        return $this->testContext()->getNode($path);
    }

    /**
     * The node of a path in the "live" workspace.
     */
    protected function nodeInLiveWorkspace(string $path): ?NodeInterface
    {
        $context = $this->contextFactory->create(['workspaceName' => 'live']);

        return $context->getNode($path);
    }

    /**
     * The context of the "test" workspace.
     */
    protected function testContext(): Context
    {
        return $this->contextFactory->create(['workspaceName' => 'test']);
    }

    /**
     * The live workspace, as it is in the content repository.
     */
    protected function liveWorkspace(): Workspace
    {
        $workspace = $this->workspaceRepository->findOneByName('live');
        self::assertInstanceOf(Workspace::class, $workspace);

        return $workspace;
    }

    /**
     * The test workspace, as it is in the content repository.
     */
    protected function testWorkspace(): Workspace
    {
        $workspace = $this->workspaceRepository->findOneByName('test');
        self::assertInstanceOf(Workspace::class, $workspace);

        return $workspace;
    }
}
