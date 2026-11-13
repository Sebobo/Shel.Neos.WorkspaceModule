<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\SiteService;

/**
 * Resolves the node of a preview in the site the node belongs to.
 *
 * The preview of a document is rendered on the host the review is opened on - not on the
 * domain of the site the document belongs to. The site is taken from the path of the node
 * instead of from the host of the request, because the frontend would otherwise render the
 * page of the site the host belongs to.
 *
 * The node itself is addressed by its path, so it is the same node in every dimension and in
 * every workspace. Only the context differs: the one the node is resolved in carries the site
 * of the node, and with it the layout the page is rendered with and the domain the links in it
 * point to.
 */
#[Flow\Scope('singleton')]
final class PreviewDocumentService
{
    public function __construct(
        private readonly ContextFactoryInterface $contextFactory,
        private readonly SiteRepository $siteRepository,
    ) {
    }

    /**
     * The node of the given context path, in the site it belongs to.
     *
     * Null when the path is not a valid context path, when it does not point into a site, or
     * when the node does not exist.
     */
    public function resolveNode(string $contextPath): ?NodeInterface
    {
        try {
            $pathAndContext = NodePaths::explodeContextPath($contextPath);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $nodePath = $pathAndContext['nodePath'];
        $site = $this->siteOfPath($nodePath);
        if (!$site instanceof Site) {
            return null;
        }

        $workspaceName = $pathAndContext['workspaceName'];
        $context = $this->contextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $pathAndContext['dimensions'],
            'currentSite' => $site,
            'currentDomain' => $this->primaryDomainOf($site),
            // A workspace that is not the live one holds content the live one does not have,
            // so the invisible content is shown - the same rule the content repository
            // applies when it resolves a node of such a workspace.
            'invisibleContentShown' => $workspaceName !== 'live',
            'inaccessibleContentShown' => false,
            'removedContentShown' => false,
        ]);

        $node = $context->getNode($nodePath);
        if (!$node instanceof NodeInterface) {
            return null;
        }

        return $node;
    }

    /**
     * The site the given path points into.
     *
     * Null when the path is not below the sites root, or when no site has the node name the
     * path carries.
     */
    private function siteOfPath(string $nodePath): ?Site
    {
        $siteNodeName = $this->siteNodeNameOfPath($nodePath);
        if ($siteNodeName === null) {
            return null;
        }

        return $this->siteRepository->findOneByNodeName($siteNodeName);
    }

    /**
     * The name of the site node the given path points into.
     *
     * Null when the path is not below the sites root.
     */
    private function siteNodeNameOfPath(string $nodePath): ?string
    {
        $sitesRoot = SiteService::SITES_ROOT_PATH . '/';
        if (!str_starts_with($nodePath, $sitesRoot)) {
            return null;
        }

        $siteNodeName = strtok(substr($nodePath, strlen($sitesRoot)), '/');

        return $siteNodeName !== false && $siteNodeName !== '' ? $siteNodeName : null;
    }

    /**
     * The domain the site is reached on.
     *
     * Null when the site has no domain - the page is then rendered without one, and the links
     * in it point to the host of the request.
     */
    private function primaryDomainOf(Site $site): ?Domain
    {
        $primaryDomain = $site->getPrimaryDomain();
        if ($primaryDomain instanceof Domain && $primaryDomain->getActive()) {
            return $primaryDomain;
        }

        $activeDomain = $site->getFirstActiveDomain();

        return $activeDomain instanceof Domain ? $activeDomain : null;
    }
}
